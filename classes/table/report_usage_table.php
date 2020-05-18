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
 * A table to display the usage of activites
 *
 * @package    report_usage
 * @copyright  Justus Dieckmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_usage\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/tablelib.php');

class report_usage_table extends \flexible_table {

    private $courseid;
    private $startdate;
    private $enddate;
    private $days;
    private $data;
    private $deanonymize;

    /**
     * report_usage_table constructor.
     *
     * @param $courseid {int}
     * @param $start {int} timestamp
     * @param $end {int} timestamp
     * @param $data {array} as returned by db_helper::get_processed_data_from_course()
     * @throws \coding_exception
     */
    public function __construct($courseid, $start, $end, $data, $downloading, $deanonymize = false) {
        parent::__construct("report_usage_" . $courseid);

        $this->courseid = $courseid;

        $startdate = new \DateTime("now", \core_date::get_server_timezone_object());
        $startdate->setTimestamp($start);
        $this->startdate = $startdate;

        $enddate = new \DateTime("now", \core_date::get_server_timezone_object());
        $enddate->setTimestamp($end);
        $this->enddate = $enddate;

        $days = intval($startdate->diff($enddate)->format('%a'));
        $this->days = $days;

        $this->data = $data;

        $this->set_attribute('class', 'generaltable generalbox');
        $this->show_download_buttons_at(array(TABLE_P_BOTTOM));

        $dt = new \DateTime("now", \core_date::get_server_timezone_object());
        $dt->setTimestamp($start);

        $cols = ['name'];
        if (!$downloading) {
            $headers = ["<div style='padding: .5rem'>" . get_string('file', 'report_usage') . "</div>"];
        } else {
            $headers = [get_string('file', 'report_usage')];
        }

        if ($deanonymize) {
            $this->deanonymize = true;
            $cols[] = 'person';
            if (!$downloading)  {
                $headers[] = "<div style='padding: .5rem'>Person</div>"; // TODO use lang string.
            } else {
                $headers[] = 'Person'; // TODO use lang string.
            }
        }

        for ($i = 0; $i <= $days; $i++) {
            $cols[] = $dt->format('Y-m-d');
            $name = $dt->format('d.m');
            if (!$downloading) {
                $headers[] = "<div style='padding: .5rem'>$name</div>";
            } else {
                $headers[] = "$name";
            }

            $dt->add(new \DateInterval("P1D"));
        }

        $this->define_columns($cols);
        $this->define_headers($headers);
        $this->pageable(false);

        $this->column_style_all('padding', '0');
        $this->column_style_all('white-space', 'nowrap');
    }

    public function init_data() {
        if ($this->deanonymize) {
            throw new \coding_exception('State mismatch.');
        }
        // Get maxima and biggest maximum.
        $biggestmax = 0;
        $maxima = [];
        // Nested lists of the form Activity -> Date -> Count.
        foreach ($this->data as $k => $a) {
            $maxima[$k] = max($a);
            if (intval($maxima[$k]) > $biggestmax) {
                $biggestmax = intval($maxima[$k]);
            }
        }

        $modinfo = get_fast_modinfo($this->courseid, -1);
        $databysection = [];

        // Create table from records.
        foreach ($this->data as $k => $a) {
            $context = \context::instance_by_id($k, IGNORE_MISSING);
            $section = $modinfo->get_cm($context->instanceid)->sectionnum;

            $name = $context->get_context_name(false, true);
            $link = $context->get_url();
            $color = $this->get_color_by_percentage(intval($maxima[$k]) / $biggestmax);
            $html = "<div style='background-color: $color; padding:  0.5rem 0.5rem 0.5rem 1rem'><a href='$link'>$name</a></div>";
            $moddata = [$this->is_downloading() ? $name : $html];

            foreach ($a as $amount) {
                $color = $this->get_color_by_percentage($amount / intval($maxima[$k]));
                if (!$this->is_downloading()) {
                    $moddata[] = "<div style='background-color: $color; padding: .5rem'>$amount</div>";
                } else {
                    $moddata[] = intval($amount);
                }
            }
            if (!isset($databysection[$section])) {
                $databysection[$section] = [];
            }
            $databysection[$section][] = $moddata;
        }
        ksort($databysection);

        foreach ($databysection as $s => $m) {
            if (!$this->is_downloading()) {
                $sectioninfo = $modinfo->get_section_info($s);
                $name = $sectioninfo->name;
                if ($name == null) {
                    $name = get_string('topic') . ' ' . $sectioninfo->section;
                }
                $sectionheader = array_merge(["<div style='padding: 0.25rem; font-weight: 300'>$name</div>"],
                        array_fill(1, $this->days + 1, ""));
                $this->add_data($sectionheader, 'report_usage-row');
            }
            foreach ($m as $d) {
                $this->add_data($d);
            }
        }
    }


    public function init_data_deanonymized() {
        global $DB;
        if (!$this->deanonymize) {
            throw new \coding_exception('State mismatch.');
        }
        // Data is nested list of the form Activity -> Person -> Date -> Count.

        $users = [];
        $modinfo = get_fast_modinfo($this->courseid, -1);

        // Create table from records.
        foreach ($this->data as $k => $p) {
            $context = \context::instance_by_id($k, IGNORE_MISSING);
            $section = $modinfo->get_cm($context->instanceid)->sectionnum;

            $modname = $context->get_context_name(false, true);
            $modlink = $context->get_url();
            $modhtml = "<div style='padding:  0.5rem 0.5rem 0.5rem 1rem'><a href='$modlink'>$modname</a></div>";

            foreach ($p as $userid => $a) {
                if (!isset($users[$userid])) {
                    $users[$userid] = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
                }
                $username = fullname($users[$userid]);
                $userlink = (new \moodle_url('/user/view.php', ['id' => $userid]))->out();
                $userhtml = "<div style='padding:  0.5rem 0.5rem 0.5rem 1rem'><a href='$userlink'>$username</a></div>";

                $rowdata = [$this->is_downloading() ? $modname : $modhtml];
                $rowdata[] = $this->is_downloading() ? $username : $userhtml;

                foreach ($a as $amount) {
                    if (!$this->is_downloading()) {
                        $rowdata[] = "<div style='padding: .5rem'>$amount</div>";
                    } else {
                        $rowdata[] = intval($amount);
                    }
                }
                $this->add_data($rowdata);
            }

        }

    }

    protected function get_color_by_percentage($per) {
        $r = 255;
        $g = $b = 255 - intval($per * 125);

        $str = "#";
        $str .= str_pad(dechex($r), 2, "0", STR_PAD_LEFT);
        $str .= str_pad(dechex($g), 2, "0", STR_PAD_LEFT);
        $str .= str_pad(dechex($b), 2, "0", STR_PAD_LEFT);
        return $str;
    }
}
