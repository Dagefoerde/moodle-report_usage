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
 * A page to display an analysis of activity by users
 *
 * @package    report_usage
 * @copyright  Justus Dieckmann <justusdieckmann@wwu.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_usage\util;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
$id = required_param('id', PARAM_INT); // Course ID.
$tab = optional_param('tab', 'table-tab', PARAM_ALPHANUMEXT);
$logformat = optional_param('download', '', PARAM_ALPHA);
$uniqueusers = (bool) optional_param('uniqueusers', false, PARAM_BOOL);
$startdate = optional_param_array('startdate', null, PARAM_INT);
$enddate = optional_param_array('enddate', null, PARAM_INT);
$roles = optional_param_array('roles', null, PARAM_INT);
$sections = optional_param_array('sections', null, PARAM_INT);
$gradecats = optional_param_array('gc', null, PARAM_INT);
$deanonymize = (bool) optional_param('deanonymize', false, PARAM_BOOL);

$params = [];
$params['id'] = $id;

$url = new moodle_url('/report/usage/index.php', array('id' => $id));

$PAGE->set_url($url);
$PAGE->set_pagelayout('report');

$course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($course->id);

if ($logformat !== '') {
    $params['download'] = $logformat;
}

if ($deanonymize) {
    if (!is_siteadmin()) {
        // This is not supposed to be used by the ordinary user.
        $deanonymize = false;
    } else {
        $params['deanonymize'] = 1;
    }

$start = $course->startdate;
$end = time();
if ($course->enddate && $course->enddate < $end) {
    $end = $course->enddate;
}

if ($startdate && !empty($startdate)) {
    $params = array_merge($params, util::make_array_params('startdate', $startdate));
    $startdate = new DateTime($startdate['year'] . '/' . $startdate['month'] . '/' . $startdate['day'],
            \core_date::get_server_timezone_object());
    $start = $startdate->getTimestamp();
}
if ($enddate && !empty($enddate)) {
    $params = array_merge($params, util::make_array_params('enddate', $enddate));
    $enddate = new DateTime($enddate['year'] . '/' . $enddate['month'] . '/' . $enddate['day'],
            \core_date::get_server_timezone_object());
    $end = $enddate->getTimestamp();
}
if ($roles && !empty($roles)) {
    $params = array_merge($params, util::make_array_params('roles', $roles));
}
if ($sections && !empty($sections)) {
    $params = array_merge($params, util::make_array_params('sections', $sections));
}
if ($gradecats && !empty($gradecats)) {
    $params = array_merge($params, util::make_array_params('gc', $gradecats));
}

require_capability('report/usage:view', $context);

$PAGE->set_title($course->shortname . ': ' . get_string('pluginname', 'report_usage'));
$PAGE->set_heading($course->fullname);

$modinfo = get_fast_modinfo($course, -1);

list($roleids, $rolenames) = \report_usage\db_helper::get_roles_in_course_for_select($context);
list($sectionids, $sectionnames) = \report_usage\db_helper::get_sections_in_course_for_select($course->id);
list($gradecatids, $gradecatnames) = \report_usage\db_helper::get_gradecategories_in_course_for_select($course->id);

$customdata = array(
        'startyear' => date('Y', $course->timecreated),
        'stopyear' => date('Y'),
        'roles' => $rolenames,
        'sections' => $sectionnames,
        'gc' => $gradecatnames
);
$mform = new \report_usage\filter_form(new moodle_url('/report/usage/index.php'), $customdata, 'get');
if ($mform->is_cancelled()) {
    redirect($url);
}
$url->params($params);

$default = array('startdate' => $start, 'enddate' => $end, 'id' => $id);


$selectedroles = null;
// If no or all roles are selected, disable role filtering.
if ($roles != null && count($roles) !== 0 && count($roles) !== count($roleids)) {
    foreach ($roles as $r) {
        $selectedroles[] = $roleids[$r];
    }
}

$selectedsections = null;
// If no or all sections are selected, disable section filtering.
if ($sections != null && count($sections) !== 0 && count($sections) !== count($sectionids)) {
    foreach ($sections as $s) {
        $selectedsections[] = $sectionids[$s];
    }
}

$selectedgradecats = null;
// If no or all sections are selected, disable section filtering.
if ($gradecats != null && count($gradecats) !== 0 && count($gradecats) !== count($gradecatids)) {
    foreach ($gradecats as $gc) {
        $selectedgradecats[] = $gradecatids[$gc];
    }
}
$data = \report_usage\db_helper::get_processed_data_from_course($id, $context, $selectedroles,
            $selectedsections, $selectedgradecats, $start, $end, $uniqueusers, $deanonymize);

$table = new \report_usage\table\report_usage_table($id, $start, $end, $data, $logformat !== '', $deanonymize);

$table->define_baseurl($url);
$table->is_downloadable(true);
$table->show_download_buttons_at(array(TABLE_P_BOTTOM));

if ($logformat !== '') {
    $table->is_downloading($logformat, 'name');
    $table->setup();
    if ($deanonymize) {
        $table->init_data_deanonymized();
    } else {
        $table->init_data();
    }
}
    $table->finish_output();
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading($course->fullname . ': ' . get_string('pluginname', 'report_usage'));

// Set default data (if any).
$mform->set_data($default);
$mform->display();

if (count($data) == 0) {
    echo \html_writer::tag('h3', get_string('no-data', 'report_usage'), array('class' => 'center'));
    echo $OUTPUT->footer();
    die();
}

ob_start();
$table->setup();
if ($deanonymize) {
    $table->init_data_deanonymized();
} else {
    $table->init_data();
}
$table->finish_html();
$tableoutput = ob_get_clean();

$mustacheparams = array('table' => $tableoutput);
if ($tab && $tab == 'chart-tab') {
    $mustacheparams['chart-tab'] = true;
} else {
    $mustacheparams['table-tab'] = true;
}
echo $OUTPUT->render_from_template('report_usage/tabs', $mustacheparams);

$chartdata = new \report_usage\output\report_usage_chart($start, $end, $id);
$names = $chartdata->get_names($data);

// The warning is weird, we decided it doesn't make sense in this case.
// Sending data via AJAX wouldn't be more efficient, because you can't cache the data on the client.
$PAGE->requires->js_call_amd('report_usage/init', 'init', array($data, $chartdata->create_labels(), $names, $id));
$PAGE->requires->js_call_amd('report_usage/tabs', 'init');

echo $OUTPUT->footer();
