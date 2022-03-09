<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * user signup page EXTENDED with optional enrolment token handler (imlpemented in local/signuptoken/lib.php)
 *
 * @package    core
 * @subpackage auth
 * @copyright  1999 onwards Martin Dougiamas  http://dougiamas.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// customscripts already reference $CFG;
if (!file_exists($CFG->dirroot.'/enrol/token/lib.php')) {
    return; // No token enrolment plugin; customscript can't execute. Return to regular script
} 

// sign up the user in the same way that /auth/email/auth.php does
// then check the token code, if set, and enrol them using that code
function CUSTOMSCRIPT_user_signup_with_confirmation($user, $notify=true, $confirmationurl = null) {
    global $CFG, $DB, $SESSION;
    require_once($CFG->dirroot.'/user/profile/lib.php');
    require_once($CFG->dirroot.'/user/lib.php');

    $plainpassword = $user->password;
    $user->password = hash_internal_user_password($user->password);
    if (empty($user->calendartype)) {
        $user->calendartype = $CFG->calendartype;
    }

    $user->id = user_create_user($user, false, false);

    user_add_password_history($user->id, $plainpassword);

    // Save any custom profile field information.
    profile_save_data($user);

    // Save wantsurl against user's profile, so we can return them there upon confirmation.
    if (!empty($SESSION->wantsurl)) {
        set_user_preference('auth_email_wantsurl', $SESSION->wantsurl, $user);
    }

    // Trigger event.
    \core\event\user_created::create_from_userid($user->id)->trigger();

    // now, about that token
    if (isset($user->token) && !empty($user->token)) {
        require_once($CFG->dirroot.'/enrol/token/lib.php');
        $etp = new enrol_token_plugin();
        $etp->perform_trusted_enrolment($user->token, $user);
    }

    if (! send_confirmation_email($user, $confirmationurl)) {
        print_error('auth_emailnoemail', 'auth_email');
    }

    if ($notify) {
        global $CFG, $PAGE, $OUTPUT;
        $emailconfirm = get_string('emailconfirm');
        $PAGE->navbar->add($emailconfirm);
        $PAGE->set_title($emailconfirm);
        $PAGE->set_heading($PAGE->course->fullname);
        echo $OUTPUT->header();
        notice(get_string('emailconfirmsent', '', $user->email), "$CFG->wwwroot/index.php"); // exits after drawing a box
    } else {
        return true;
    }
}

require_once($CFG->dirroot . '/user/editlib.php');
require_once($CFG->libdir . '/authlib.php');
require_once($CFG->dirroot . '/login/lib.php');

if (!$authplugin = signup_is_enabled()) {
    print_error('notlocalisederrormessage', 'error', '', 'Sorry, you may not use this page.');
}

$PAGE->set_url('/login/signup.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');

// If wantsurl is empty or /login/signup.php, override wanted URL.
// We do not want to end up here again if user clicks "Login".
if (empty($SESSION->wantsurl)) {
    $SESSION->wantsurl = $CFG->wwwroot . '/';
} else {
    $wantsurl = new moodle_url($SESSION->wantsurl);
    if ($PAGE->url->compare($wantsurl, URL_MATCH_BASE)) {
        $SESSION->wantsurl = $CFG->wwwroot . '/';
    }
}

if (isloggedin() and !isguestuser()) {
    // Prevent signing up when already logged in.
    echo $OUTPUT->header();
    echo $OUTPUT->box_start();
    $logout = new single_button(new moodle_url('/login/logout.php',
        array('sesskey' => sesskey(), 'loginpage' => 1)), get_string('logout'), 'post');
    $continue = new single_button(new moodle_url('/'), get_string('cancel'), 'get');
    echo $OUTPUT->confirm(get_string('cannotsignup', 'error', fullname($USER)), $logout, $continue);
    echo $OUTPUT->box_end();
    echo $OUTPUT->footer();
    exit;
}

// If verification of age and location (digital minor check) is enabled.
if (\core_auth\digital_consent::is_age_digital_consent_verification_enabled()) {
    $cache = cache::make('core', 'presignup');
    $isminor = $cache->get('isminor');
    if ($isminor === false) {
        // The verification of age and location (minor) has not been done.
        redirect(new moodle_url('/login/verify_age_location.php'));
    } else if ($isminor === 'yes') {
        // The user that attempts to sign up is a digital minor.
        redirect(new moodle_url('/login/digital_minor.php'));
    }
}

// Plugins can create pre sign up requests.
// Can be used to force additional actions before sign up such as acceptance of policies, validations, etc.
core_login_pre_signup_requests();

$mform_signup = $authplugin->signup_form();

if ($mform_signup->is_cancelled()) {
    redirect(get_login_url());

} else if ($user = $mform_signup->get_data()) {
    // Add missing required fields.
    $user = signup_setup_new_user($user);
    // Plugins can perform post sign up actions once data has been validated.
    core_login_post_signup_requests($user);

    // CUSTOM VERSION OF /auth/email/auth.php -> user_signup()
    CUSTOMSCRIPT_user_signup_with_confirmation($user);

    exit; //never reached
}


$newaccount = get_string('newaccount');
$login      = get_string('login');

$PAGE->navbar->add($login);
$PAGE->navbar->add($newaccount);

$PAGE->set_pagelayout('login');
$PAGE->set_title($newaccount);
$PAGE->set_heading($SITE->fullname);

echo $OUTPUT->header();

if ($mform_signup instanceof renderable) {
    // Try and use the renderer from the auth plugin if it exists.
    try {
        $renderer = $PAGE->get_renderer('auth_' . $authplugin->authtype);
    } catch (coding_exception $ce) {
        // Fall back on the general renderer.
        $renderer = $OUTPUT;
    }
    echo $renderer->render($mform_signup);
} else {
    // Fall back for auth plugins not using renderables.
    $mform_signup->display();
}
echo $OUTPUT->footer();
die(); // as you do, in a customscript