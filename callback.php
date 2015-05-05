<?php

/**
 * Listens for Instant Payment Notification from saman
 *
 * This script waits for Payment notification from saman,
 * then double checks that data by sending it back to saman.
 * If saman verifies this then it sets up the enrolment for that
 * user.
 *
 * @package    enrol_saman
 * @copyright 2010 Eugene Venter
 * @author     Eugene Venter - based on code by others
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require("../../config.php");
require_once("lib.php");
require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT);

/// Keep out casual intruders
if (empty($_POST['ResNum']) || empty($_POST['RefNum']) || empty($_POST['State'])) {
  print_error("Sorry, you can not use the script that way.");
}

$data = new stdClass();
$data->reservation_number = $_POST['ResNum'];
$data->reference_number = $_POST['RefNum'];
$data->transaction_state = $_POST['State'];

/// get the user and course records
if (!$transaction = $DB->get_record("enrol_saman", array("id" => $data->reservation_number))) {
  message_saman_error_to_admin("Not a valid reservation_number", $data);
  die;
}

if (!$user = $DB->get_record("user", array("id" => $transaction->userid))) {
  message_saman_error_to_admin("Not a valid user id", $data);
  die;
}

if (!$course = $DB->get_record("course", array("id" => $transaction->courseid))) {
  message_saman_error_to_admin("Not a valid course id", $data);
  die;
}

if (!$context = context_course::instance($course->id, IGNORE_MISSING)) {
  message_saman_error_to_admin("Not a valid context id", $data);
  die;
}

if (!$plugin_instance = $DB->get_record("enrol", array("id" => $transaction->instanceid, "status" => 0))) {
  message_saman_error_to_admin("Not a valid instance id", $data);
  die;
}

$plugin = enrol_get_plugin('saman'); //here

if ($data->transaction_state == 'OK') {

  // ALL CLEAR !
  $transaction->reference_number = $data->reference_number;
  $transaction->transaction_state = $data->transaction_state;
  $transaction->timeupdated = time();

  $DB->update_record("enrol_saman", $transaction);

  // Check that amount paid is the correct amount
  if ((float) $plugin_instance->cost <= 0) {
    $cost = (float) $plugin->get_config('cost');
  } else {
    $cost = (float) $plugin_instance->cost;
  }

  // Use the same rounding of floats as on the enrol form.
  $cost = format_float($cost, 0, false);

  try {
    $client = new SoapClient(SAMAN_SERVICE);
    $result = $client->VerifyTransaction($transaction->reference_number, $plugin->get_config('merchant_id'));
    if ($result == $cost) {
      
    } else {
	  if ($result > 0) {
        message_saman_error_to_admin("Amount paid is not enough ($result < $cost))", $result);
	  } else {
		message_saman_error_to_admin("Error code: $result", $result);
	  }
      die;
    }
  } catch (Exception $ex) {
    message_saman_error_to_admin("Amount paid is not enough ($result < $cost))", $result);
    die;
  }

  // Enrol user
  if ($plugin_instance->enrolperiod) {
    $timestart = time();
    $timeend = $timestart + $plugin_instance->enrolperiod;
  } else {
    $timestart = 0;
    $timeend = 0;
  }
  $plugin->enrol_user($plugin_instance, $user->id, $plugin_instance->roleid, $timestart, $timeend);

  // Start sending messages
  $coursecontext = context_course::instance($course->id, IGNORE_MISSING);

  // Pass $view=true to filter hidden caps if the user cannot see them
  if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC', '', '', '', '', false, true)) {
    $users = sort_by_roleassignment_authority($users, $context);
    $teacher = array_shift($users);
  } else {
    $teacher = false;
  }

  $mailstudents = $plugin->get_config('mailstudents');
  $mailteachers = $plugin->get_config('mailteachers');
  $mailadmins = $plugin->get_config('mailadmins');
  $shortname = format_string($course->shortname, true, array('context' => $context));
  if (!empty($mailstudents)) {
    $a->coursename = format_string($course->fullname, true, array('context' => $coursecontext));
    $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";

    $eventdata = new stdClass();
    $eventdata->modulename = 'moodle';
    $eventdata->component = 'enrol_saman';
    $eventdata->name = 'saman_enrolment';
    $eventdata->userfrom = $teacher;
    $eventdata->userto = $user;
    $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
    $eventdata->fullmessage = get_string('welcometocoursetext', '', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';
    $eventdata->smallmessage = '';
    message_send($eventdata);
  }

  if (!empty($mailteachers)) {
    $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
    $a->user = fullname($user);

    $eventdata = new stdClass();
    $eventdata->modulename = 'moodle';
    $eventdata->component = 'enrol_saman';
    $eventdata->name = 'saman_enrolment';
    $eventdata->userfrom = $user;
    $eventdata->userto = $teacher;
    $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
    $eventdata->fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';
    $eventdata->smallmessage = '';
    message_send($eventdata);
  }

  if (!empty($mailadmins)) {
    $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
    $a->user = fullname($user);
    $admins = get_admins();
    foreach ($admins as $admin) {
      $eventdata = new stdClass();
      $eventdata->modulename = 'moodle';
      $eventdata->component = 'enrol_saman';
      $eventdata->name = 'saman_enrolment';
      $eventdata->userfrom = $user;
      $eventdata->userto = $admin;
      $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
      $eventdata->fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
      $eventdata->fullmessageformat = FORMAT_PLAIN;
      $eventdata->fullmessagehtml = '';
      $eventdata->smallmessage = '';
      message_send($eventdata);
    }
  }

  if (!empty($SESSION->wantsurl)) {
    $destination = $SESSION->wantsurl;
    unset($SESSION->wantsurl);
  } else {
    $destination = "$CFG->wwwroot/course/view.php?id=$course->id";
  }

  $fullname = format_string($course->fullname, true, array('context' => $context));
  redirect($destination, get_string('paymentthanks', '', $fullname));
} else {
  if (!empty($SESSION->wantsurl)) {
    $destination = $SESSION->wantsurl;
    unset($SESSION->wantsurl);
  } else {
    $destination = "$CFG->wwwroot/course/view.php?id=$course->id";
  }

  $fullname = format_string($course->fullname, true, array('context' => $context));
  $PAGE->set_url($destination);
  echo $OUTPUT->header();
  $a = new stdClass();
  $a->teacher = get_string('defaultcourseteacher');
  $a->fullname = $fullname;
  notice(get_string('paymentsorry', '', $a), $destination);
}

function message_saman_error_to_admin($subject, $data) {
  echo $subject;
  $admin = get_admin();
  $site = get_site();

  $message = "$site->fullname:  Transaction failed.\n\n$subject\n\n";

  foreach ($data as $key => $value) {
    $message .= "$key => $value\n";
  }

  $eventdata = new stdClass();
  $eventdata->modulename = 'moodle';
  $eventdata->component = 'enrol_saman';
  $eventdata->name = 'saman_enrolment';
  $eventdata->userfrom = $admin;
  $eventdata->userto = $admin;
  $eventdata->subject = "PAYPAL ERROR: " . $subject;
  $eventdata->fullmessage = $message;
  $eventdata->fullmessageformat = FORMAT_PLAIN;
  $eventdata->fullmessagehtml = '';
  $eventdata->smallmessage = '';
  message_send($eventdata);
}
