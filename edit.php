<?php

/**
 * Adds new instance of enrol_saman to specified course
 * or edits current instance.
 *
 * @package    enrol_saman
 * @copyright  2010 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require('../../config.php');
require_once('edit_form.php');

$courseid = required_param('courseid', PARAM_INT);
$instanceid = optional_param('id', 0, PARAM_INT); // instanceid

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id, MUST_EXIST);

require_login($course);
require_capability('enrol/saman:config', $context);

$PAGE->set_url('/enrol/saman/edit.php', array('courseid' => $course->id, 'id' => $instanceid));
$PAGE->set_pagelayout('admin');

$return = new moodle_url('/enrol/instances.php', array('id' => $course->id));
if (!enrol_is_enabled('saman')) {
  redirect($return);
}

$plugin = enrol_get_plugin('saman');

if ($instanceid) {
  $instance = $DB->get_record('enrol', array('courseid' => $course->id, 'enrol' => 'saman', 'id' => $instanceid), '*', MUST_EXIST);
  $instance->cost = format_float($instance->cost, 0, true);
} else {
  require_capability('moodle/course:enrolconfig', $context);
  // no instance yet, we have to add new instance
  navigation_node::override_active_url(new moodle_url('/enrol/instances.php', array('id' => $course->id)));
  $instance = new stdClass();
  $instance->id = null;
  $instance->courseid = $course->id;
}

$mform = new enrol_saman_edit_form(NULL, array($instance, $plugin, $context));

if ($mform->is_cancelled()) {
  redirect($return);
} else if ($data = $mform->get_data()) {
  if ($instance->id) {
    $reset = ($instance->status != $data->status);

    $instance->status = $data->status;
    $instance->name = $data->name;
    $instance->cost = unformat_float($data->cost);
    $instance->currency = $data->currency;
    $instance->roleid = $data->roleid;
    $instance->enrolperiod = $data->enrolperiod;
    $instance->enrolstartdate = $data->enrolstartdate;
    $instance->enrolenddate = $data->enrolenddate;
    $instance->timemodified = time();
    $DB->update_record('enrol', $instance);

    if ($reset) {
      $context->mark_dirty();
    }
  } else {
    $fields = array('status' => $data->status, 'name' => $data->name, 'cost' => unformat_float($data->cost), 'currency' => $data->currency, 'roleid' => $data->roleid,
        'enrolperiod' => $data->enrolperiod, 'enrolstartdate' => $data->enrolstartdate, 'enrolenddate' => $data->enrolenddate);
    $plugin->add_instance($course, $fields);
  }

  redirect($return);
}

$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('pluginname', 'enrol_saman'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'enrol_saman'));
$mform->display();
echo $OUTPUT->footer();
