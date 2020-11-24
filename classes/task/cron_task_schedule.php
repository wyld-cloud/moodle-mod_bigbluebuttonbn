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
namespace mod_bigbluebuttonbn\task;

use context_module;

defined('MOODLE_INTERNAL') || die();

/**
 * Transfer schedules to loadbalancer in advance of starting rooms.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2020 Stefan Markmann <sm@wyld.cloud>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cron_task_schedule extends \core\task\scheduled_task {
    
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('transfer_schedules', 'mod_bigbluebuttonbn');
    }

    /**
     * A cronjob preparing and posting the current state of this customers activity planning. 
     * This way deletions and additions will be transmitted, missing items can be detected. 
     * The api should just be called, if activity-plan is changed relevantly.
     */
    public function execute() {
        global $CFG, $DB;
        
        // Check if transfer is enabled, else quit with message
        if(!$CFG->transferschedule_enabled){
            mtrace('transferschedule_enabled is disabled by config');
            return;
        }

        // Get courses with bbb instances as activity and their schedule
        $sql = "
            SELECT
                cm.id AS courseModuleId,
                m.id as moduleInstanceId,
                m.course as courseId,
                m.name as courseModuleName,
                m.meetingid,
                m.openingtime,
                m.closingtime,
                m.participants,
                m.userlimit
            FROM
                {course_modules} cm
                JOIN {modules} md ON md.id = cm.module
                JOIN {bigbluebuttonbn} m ON m.id = cm.instance
            WHERE
                md.name = 'bigbluebuttonbn' AND
                (
                	(m.closingtime = 0) OR
                	(m.closingtime > ?)
                )
        ";
        $rawSchedules = $DB->get_records_sql($sql, array(time()));
        mtrace('Number of activities: '.count($rawSchedules));
        
        $data = array();
        foreach ($rawSchedules as $rawSchedule) {
            // Determine exact number of invited students based on groups and selection
            $contextmodule = context_module::instance($rawSchedule->coursemoduleid);
            $users = (array) get_enrolled_users($contextmodule, '', 0, 'u.*', null, 0, 0, true);
            $numParticipants = count($users);
            mtrace('Number of enrolled users: '.$numParticipants);
            $rawSchedule->participants = $numParticipants;
            $data[] = $rawSchedule;
        }
        
        // Cache params, only transfer if changed
        $dataJson = json_encode($data);
        make_temp_directory('bigbluebuttonbn');        
        $tempFile = $CFG->tempdir . '/bigbluebuttonbn/schedule.json';
        mtrace('Cache-File: '.$tempFile);
        mtrace('Content: '.$dataJson);
        
        // Check if cached params match current
        $fileContents = file_get_contents($tempFile);
        if($fileContents != $dataJson){
            
            // The bbb-api uses the secret to hash the call - but repeted calls may be possible when url is known.
            // Also encoding as get-parameters has known limits in length - but not always. https://stackoverflow.com/questions/2659952/maximum-length-of-http-get-request
            // We'll just put the json in the body and submit a hash of the contents as parameter - authed by sha1-salt of bbb.
            $params = Array();
            $params["bodyhash"] = sha1($dataJson); 
            
            // Get lb address
            $url = \mod_bigbluebuttonbn\locallib\bigbluebutton::action_url('schedule', $params);
            mtrace('Calling api: '.$url);
            
            // Rest call
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $dataJson);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Accept: application/json'
            ));
            $result = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Check if the other side could work this event, if not, try again later
            if($statusCode >= 200 && $statusCode < 300){
                mtrace('API answered okay: '.$statusCode);
                // Remember for next time
                file_put_contents($tempFile, $paramsJson);
            } else {
                mtrace('statusCode: '.$statusCode);
                mtrace('result: '.$result);
                throw new \Exception("API answered with code ".$statusCode);
            }
            
        } else {
            mtrace('No updated scheduled bigbluebutton meetings found.');
        }
        
    }
}




