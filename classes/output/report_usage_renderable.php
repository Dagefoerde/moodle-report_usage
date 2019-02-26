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
 * Activity analysis rendererable
 *
 * @package    report_usage
 * @copyright  2019 Justus Dieckmann <justusdieckmann@wwu.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_usage\output;

defined('MOODLE_INTERNAL') || die();

class report_usage_renderable implements \renderable {

    public $days;
    public $cid;

    public function __construct($days, $cid) {
        $this->days = $days;
        $this->cid = $cid;
    }

    public function get_data() {
        global $DB;

        $date = new \DateTime($this->days . " days ago");
        $params = array($this->cid, $date->format("Ymd"));
        $sql = "SELECT MIN(id) AS id, contextid, yearcreated, monthcreated, daycreated, SUM(amount) AS amount
                  FROM {logstore_usage_log} 
                 WHERE courseid = ? AND yearcreated * 10000 + monthcreated * 100 + daycreated >= ?
              GROUP BY contextid, yearcreated, monthcreated, daycreated
              ORDER BY contextid";

        $records = $DB->get_records_sql($sql, $params);

        $output = [];
        $max_amount = 0;
        foreach ($records as $v) {
            if($v->amount > $max_amount)
                $max_amount = $v->amount;

            if(!isset($output[$v->contextid])) {
                $output[$v->contextid] = [];
            }
            $diff = new \DateTime("$v->daycreated-$v->monthcreated-$v->yearcreated");
            $datediff = intval($diff->diff($date, true)->format("%a"));
            $output[$v->contextid][$datediff] = $v;
        }
        return array($output, $max_amount);
    }

}