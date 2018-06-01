<?php
/*
* Signature-Based Single Sign-On Framework
* TPA Adapter for
* Moodle (http://www.moodle.org)
*
*  Version            : 0.2 
*  Last update        : 09.05.2018
*
*  (c) Bitmotion GmbH, Hannover, Germany
*  http://www.single-signon.com
*/

/*************** configure adapter here ***************/
$moodle_sso['root'] = '/path/to/moodle/root/directory';
$moodle_sso['lang'] = 'de';
$moodle_sso['mnet_host_id'] = 1;
/*************** nothing to edit below! ***************/
$GLOBALS['moodle_sso'] = $moodle_sso;

/**
 * @return string
 */
function get_version()
{
    return '2.1';
}

function randomPassword()
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890*#-_.:,;';
    $password = [];
    $alphabetLength = strlen($alphabet) - 1;
    for ($i = 0; $i < 40; $i++) {
        $n = rand(0, $alphabetLength);
        $password[] = $alphabet[$n];
    }
    return implode($password);
}

/************************************************************
 *  load config                                              *
 *  for now this has to be done outside the function for the *
 *  setup of the global vars to work                         *
 ************************************************************/

include_once($moodle_sso['root'] . 'config.php');

function sso($username, $ip, $agent, $sso_url, $sso_version = '', $sso_action = '', $sso_userdata = [])
{
    global $USER, $CFG, $DB;
    $moodle_sso = $GLOBALS['moodle_sso'];

    if ($sso_version == '') {
        return ['Error' => 'sso version out of date'];
    }

    $username = trim(strtolower($username));
    switch ($sso_action) {
        case 'create_modify':
            $user = new \stdClass();
            include_once($moodle_sso['root'] . 'lang/en/countries.php');
            /** @var array $string */
            foreach ($string as $key => $value) {
                if (strtolower($sso_userdata['country']) == strtolower($value)) {
                    $user->country = $key;
                }
            }

            // try configured language as well... same as above
            if (!$user->country) {
                include_once($moodle_sso['root'] . 'lang/' . $moodle_sso['lang'] . '/countries.php');
                foreach ($string as $key => $value) {
                    if (strtolower($sso_userdata['country']) == strtolower($value)) {
                        $user->country = $key;
                    }
                }
            }

            // fallback to moodle's default if nothing else works
            if (!$user->country) {
                $user->country = $CFG->country;
            }

            $name = $sso_userdata['name'] ?: '';
            $name = explode(' ', $name);
            if (count($name) >= 2) {
                $firstName = $name[0];
                unset($name[0]);
                $lastName = implode(' ', $name);
            } elseif (count($name) === 1) {
                $firstName = '-';
                $lastName = $name[0];
            } else {
                $firstName = '';
                $lastName = '';
            }

            // set up the user object
            $user->username    = $username;
            $user->password    = randomPassword();
            $user->confirmed   = 1;
            $user->email       = $sso_userdata['email'];
            $user->firstname   = $firstName;
            $user->lastname    = $lastName;
            $user->phone1      = $sso_userdata['telephone'] ?: '';
            $user->address     = $sso_userdata['address'] ?: '';
            $user->city        = $sso_userdata['city'] ?: '';
            $user->lang        = current_language();
            $user->firstaccess = time();
            $user->secret      = random_string(15);
            $user->auth        = 'manual';
            $user->mnethostid  = $moodle_sso['mnet_host_id'];

            include_once($moodle_sso['root'] . 'user/lib.php');
            $existingUser = get_complete_user_data('username', $username);
            if ($existingUser === false) {
                try {
                    $id = user_create_user($user);
                } catch (\moodle_exception $exception) {
                    return ['Error' => $exception->getMessage()];
                }
            } else {
                $user->id = $existingUser->id;
                try {
                    user_update_user($user);
                } catch (\moodle_exception $exception) {
                    return ['Error' => $exception->getMessage()];
                }
            }

            $enrolledCourses = enrol_get_users_courses($user->id);
            $enrolledIds = [];
            if (!empty($sso_userdata['courses'])) {
                $courses = explode(',', $sso_userdata['courses']);
                for ($i = 0; $i < count($courses); $i++) {
                    $courses[$i] = explode(':', $courses[$i]);
                }

                foreach ($courses as $courseAndRole) {
                    $courseId = $courseAndRole[0];
                    $roleId = $courseAndRole[1];
                    $course = $DB->get_record('course', ['id' => $courseId], '*', MUST_EXIST);
                    $context = context_course::instance($course->id);
                    $assignedRoles = get_user_roles($context, $user->id);
                    foreach ($assignedRoles as $role) {
                        if ($role->roleid != $roleId) {
                            role_unassign($role->roleid, $user->id, $context->id);
                        }
                    }
                    $enrol = enrol_get_plugin('manual');
                    $instances = enrol_get_instances($course->id, true);
                    $manualinstance = null;
                    foreach ($instances as $instance) {
                        if ($instance->name == 'manual') {
                            $manualinstance = $instance;
                            break;
                        }
                    }
                    $enrol->enrol_user($instance, $user->id, $roleId);
                    $enrolledIds[] = $courseId;
                }
            }

            foreach ($enrolledCourses as $course) {
                if (!in_array($course->id, $enrolledIds)) {
                    $courseId = $course->id;
                    $course = $DB->get_record('course', ['id' => $courseId], '*', MUST_EXIST);
                    $enrol = enrol_get_plugin('manual');
                    $instances = enrol_get_instances($course->id, true);
                    $manualinstance = null;
                    foreach ($instances as $instance) {
                        if ($instance->name == 'manual') {
                            $manualinstance = $instance;
                            break;
                        }
                    }
                    $enrol->unenrol_user($instance, $user->id);
                }
            }

            unset($existingUser);
            unset($user);
            break;
        case 'logon':
            $user = get_complete_user_data('username', $username);
            if ($user === false) {
                return ['Error' => 'no account for this user'];
            } else {
                // copy user object to global $USER var
                $USER = $user;
                update_user_login_times();
                set_moodle_cookie($USER->username);
                set_login_session_preferences();

                $return_val = ['redirecturl' => $sso_url];
                return $return_val;
            }
            break;
        case 'logoff':
            set_moodle_cookie('');
            \core\session\manager::terminate_current();
            break;
    }
}
