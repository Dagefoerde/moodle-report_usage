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
 * Helper class with methods to query the database
 *
 * @package   report_usage
 * @copyright 2019 Justus Dieckmann
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_usage;

defined('MOODLE_INTERNAL') || die();

/**
 * Class filter_form form to filter the results by date
 *
 * @package report_outline
 */
class db_helper {

    /**
     * @param $coursecontext \context_course
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_roles_in_course_for_select($coursecontext) {
        global $DB;

        list($contextlist, $cparams) = $DB->get_in_or_equal($coursecontext->get_parent_context_ids(true), SQL_PARAMS_NAMED);
        $sql = "SELECT DISTINCT r.id, r.shortname
                FROM {role_assignments} ra
                INNER JOIN {role} r ON r.id = ra.roleid
                WHERE contextid $contextlist";

        $result = $DB->get_records_sql_menu($sql, $cparams);
        return array(array_keys($result), array_values($result));
    }

    public static function get_mods_in_sections($sectionids, $courseid) {
        global $DB;

        $sql = "SELECT con.id FROM {context} con
                JOIN {course_modules} cm
                ON con.instanceid = cm.id
                WHERE cm.course = :courseid
                AND con.contextlevel = 70";

        $params = [];

        if ($sectionids != null && count($sectionids) != 0) {
            list($sectionlist, $params) = $DB->get_in_or_equal($sectionids, SQL_PARAMS_NAMED);
            $sql .= "AND cm.section $sectionlist";
        }

        $params['courseid'] = $courseid;

        return array_keys($DB->get_records_sql_menu($sql, $params));

    }

    public static function get_mods_in_gradecategories($gradecatids) {
        global $DB;
        list($gradecatlist, $params) = $DB->get_in_or_equal($gradecatids, SQL_PARAMS_NAMED);
        $sql = "SELECT con.id
                FROM {grade_items} gi
                         JOIN {modules} m
                              ON gi.itemmodule = m.name
                         JOIN {course_modules} cm
                              ON cm.module = m.id AND cm.instance = gi.iteminstance
                         JOIN {context} con
                              ON con.instanceid = cm.id
                WHERE gi.categoryid $gradecatlist
                  AND con.contextlevel = 70";

        return array_keys($DB->get_records_sql($sql, $params));
    }

    public static function get_sections_in_course_for_select($courseid) {
        $modinfo = get_fast_modinfo($courseid);

        $sections = [];
        $sectionids = [];

        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            $name = $sectioninfo->name;
            if ($name == null) {
                $name = get_string('topic') . ' ' . $sectioninfo->section;
            }
            $sections[] = $name;
            $sectionids[] = $sectioninfo->id;
        }
        return array($sectionids, $sections);
    }

    public static function get_gradecategories_in_course_for_select($courseid) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $gradecats = grade_get_categories_menu($courseid);
        return array(array_keys($gradecats), array_values($gradecats));
    }

    /**
     * @param $courseid
     * @param $coursecontext
     * @param $roles
     * @param $mindate
     * @param $maxdate
     * @param $uniqueusers
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_data_from_course($courseid, $coursecontext, $roles, $sections, $gradecats,
            $mindate, $maxdate, $uniqueusers = false, $deanonymize = false) {
        global $DB;

        $params = [];

        $adduserdata = '';
        $addgroupby = '';
        if ($deanonymize) {
            $adduserdata = ' ul.userid, ';
            $addgroupby = ' ul.userid,';

        }
        if ($uniqueusers) {
            $sql = "SELECT MIN(ul.id) AS id,$adduserdata ul.contextid, yearcreated, monthcreated, daycreated, COUNT(amount) AS amount
                FROM {logstore_usage_log} ul ";
        } else {
            $sql = "SELECT MIN(ul.id) AS id,$adduserdata ul.contextid, yearcreated, monthcreated, daycreated, SUM(amount) AS amount
                FROM {logstore_usage_log} ul ";

        }

        if ($roles != null && count($roles) != 0) {
            list($conlist, $conparams) =
                    $DB->get_in_or_equal($coursecontext->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'con');

            $sql .= "INNER JOIN (
                    SELECT userid, MIN(roleid) as roleid
                    FROM  {role_assignments}
                    WHERE contextid $conlist
                    GROUP BY userid, contextid
                  ) r
                    ON ul.userid = r.userid ";

            $params = array_merge($params, $conparams);
        }
        $sql .= "WHERE courseid = :courseid
                  AND yearcreated * 10000 + monthcreated * 100 + daycreated >= :mindate
                  AND yearcreated * 10000 + monthcreated * 100 + daycreated <= :maxdate ";

        if ($roles != null && count($roles) != 0) {
            list($rolelist, $roleparams) = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'role');
            $sql .= "AND r.roleid $rolelist";

            $params = array_merge($params, $roleparams);
        }

        if ($gradecats != null && count($gradecats) != 0) {
            $gradecatcontextids = self::get_mods_in_gradecategories($gradecats);
            list($gradecatlist, $gradecatparams) = $DB->get_in_or_equal($gradecatcontextids, SQL_PARAMS_NAMED, 'gradecat');
            $sql .= "AND ul.contextid $gradecatlist";

            $params = array_merge($params, $gradecatparams);
        }

        $mods = self::get_mods_in_sections($sections, $courseid);
        if (count($mods) == 0) {
            // No results when filtering for empty section.
            return array();
        }

        list($modlist, $modparams) = $DB->get_in_or_equal($mods, SQL_PARAMS_NAMED, 'mod');
        $sql .= "AND ul.contextid $modlist ";
        $params = array_merge($params, $modparams);

        $sql .= "GROUP BY ul.contextid,$addgroupby yearcreated, monthcreated, daycreated
                ORDER BY ul.contextid, yearcreated, monthcreated, daycreated ";

        $params = array_merge($params, array(
                'courseid' => $courseid,
                'coursecontextid' => $coursecontext->id,
                'mindate' => $mindate,
                'maxdate' => $maxdate
        ));
        return $DB->get_records_sql($sql, $params);
    }

    public static function get_processed_data_from_course($courseid, $coursecontextid, $roles, $sections,
            $gradecats, $mindatestamp, $maxdatestamp, $uniqueusers = false, $deanonymize = false) {
        $startdate = new \DateTime("now", \core_date::get_server_timezone_object());
        $startdate->setTimestamp($mindatestamp);

        $enddate = new \DateTime("now", \core_date::get_server_timezone_object());
        $enddate->setTimestamp($maxdatestamp);

        $days = intval($startdate->diff($enddate)->format('%a'));

        $records = self::get_data_from_course($courseid, $coursecontextid, $roles, $sections, $gradecats,
                $startdate->format("Ymd"), $enddate->format("Ymd"), $uniqueusers, $deanonymize);
        $modinfo = get_fast_modinfo($courseid, -1);

        $data = [];
        $deletedids = [];

        // Create table from records.
        foreach ($records as $v) {
            if (in_array($v->contextid, $deletedids)) {
                continue;
            }

            if (!isset($data[$v->contextid])) {
                $context = \context::instance_by_id($v->contextid, IGNORE_MISSING);
                if (!$context) {
                    $deletedids[] = $v->contextid;
                    continue;
                }
                // Also delete contexts that have no associated course module anymore.
                // Probably a bug, but this has happened with mod_hvp.
                try {
                    $modinfo->get_cm($context->instanceid);
                } catch (\moodle_exception $e) {
                    $deletedids[] = $v->contextid;
                    continue;
                }
                $data[$v->contextid] = [];
            }

            $diff = new \DateTime("$v->daycreated-$v->monthcreated-$v->yearcreated");
            $datediff = intval($diff->diff($startdate, true)->format("%a"));
            if ($deanonymize) {
                $data[$v->contextid][$v->userid][$datediff] = $v->amount;
            } else {
                $data[$v->contextid][$datediff] = $v->amount;
            }
        }

        // Fill empty cells with 0.
        if ($deanonymize) {
            for ($i = 0; $i <= $days; $i++) {
                foreach ($data as $k => $v) {
                    foreach ($v as $p => $value) {
                        if (!isset($data[$k][$p][$i])) {
                            $data[$k][$p][$i] = 0;
                        }
                    }
                }
            }

            foreach ($data as &$row) {
                foreach ($row as &$days) {
                    ksort($days);
                }
            }
        } else {
            for ($i = 0; $i <= $days; $i++) {
                foreach ($data as $k => $v) {
                    if (!isset($data[$k][$i])) {
                        $data[$k][$i] = 0;
                    }
                }
            }

            foreach ($data as &$row) {
                ksort($row);
            }
        }
        return $data;
    }

}
