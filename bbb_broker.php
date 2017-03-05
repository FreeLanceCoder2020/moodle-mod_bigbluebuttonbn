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
 * Intermediator for managing actions executed by the BigBlueButton server
 *
 * @package   mod_bigbluebuttonbn
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 * @copyright 2015 Blindside Networks Inc.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v2 or later
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/locallib.php');

use \Firebase\JWT\JWT;

global $PAGE, $USER, $CFG, $SESSION, $DB;

$params['action'] = optional_param('action', '', PARAM_TEXT);
$params['callback'] = optional_param('callback', '', PARAM_TEXT);
$params['id'] = optional_param('id', '', PARAM_TEXT); //recordID, the BBB recordID
$params['idx'] = optional_param('idx', '', PARAM_TEXT); //meetingID, the BBB meetingID
$params['bigbluebuttonbn'] = optional_param('bigbluebuttonbn', 0, PARAM_INT);
$params['signed_parameters'] = optional_param('signed_parameters', '', PARAM_TEXT);

$error = '';

if (empty($params['action'])) {
    $error = bigbluebuttonbn_bbb_broker_add_error($error, "Parameter [action] was not included");

} else {
    $error = bigbluebuttonbn_bbb_broker_validate_parameters($params);

    if (empty($error)) {
        if (isset($params['bigbluebuttonbn']) && $params['bigbluebuttonbn'] != 0) {
            $bigbluebuttonbn = $DB->get_record('bigbluebuttonbn', array('id' => $params['bigbluebuttonbn']), '*', MUST_EXIST);
            $course = $DB->get_record('course', array('id' => $bigbluebuttonbn->course), '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('bigbluebuttonbn', $bigbluebuttonbn->id, $course->id, false, MUST_EXIST);
            $context = bigbluebuttonbn_get_context_module($cm->id);
        }

        if ($params['action'] != "recording_ready" && $params['action'] != "meeting_events") {
            if (isset($SESSION->bigbluebuttonbn_bbbsession) && !is_null($SESSION->bigbluebuttonbn_bbbsession)) {
                $bbbsession = $SESSION->bigbluebuttonbn_bbbsession;
            } else {
                $error = bigbluebuttonbn_bbb_broker_add_error($error, "No session variable set");
            }
        }
    }
}

header('Content-Type: application/javascript; charset=utf-8');
if (empty($error)) {

    if (!isloggedin() && $PAGE->course->id == SITEID) {
        $userid = guest_user()->id;
    } else {
        $userid = $USER->id;
    }
    $hascourseaccess = ($PAGE->course->id == SITEID) || can_access_course($PAGE->course, $userid);

    if (!$hascourseaccess) {
        header("HTTP/1.0 401 Unauthorized");
        return;
    } else {
        $instance_type_profiles = bigbluebuttonbn_get_instance_type_profiles();
        $features = isset($bbbsession['bigbluebuttonbn']->type) ? $instance_type_profiles[$bbbsession['bigbluebuttonbn']->type]['features'] : $instance_type_profiles[0]['features'];
        $showroom = (in_array('all', $features) || in_array('showroom', $features));
        $showrecordings = (in_array('all', $features) || in_array('showrecordings', $features));
        $importrecordings = (in_array('all', $features) || in_array('importrecordings', $features));

        try {
            switch (strtolower($params['action'])) {
                case 'meeting_info':
                    $meeting_info = bigbluebuttonbn_bbb_broker_get_meeting_info($params['id'], $bbbsession['modPW']);
                    $meeting_running = bigbluebuttonbn_bbb_broker_is_meeting_running($meeting_info);

                    $status_can_end = '';
                    $status_can_tag = '';
                    if ($meeting_running) {
                        $join_button_text = get_string('view_conference_action_join', 'bigbluebuttonbn');
                        if ($bbbsession['userlimit'] == 0 || $meeting_info->participantCount < $bbbsession['userlimit']) {
                            $initial_message = get_string('view_message_conference_in_progress', 'bigbluebuttonbn');
                            $can_join = true;
                        } else {
                            $initial_message = get_string('view_error_userlimit_reached', 'bigbluebuttonbn');
                            $can_join = false;
                        }

                        if ($bbbsession['administrator'] || $bbbsession['moderator']) {
                            $end_button_text = get_string('view_conference_action_end', 'bigbluebuttonbn');
                            $can_end = true;
                            $status_can_end = '"can_end": true, "end_button_text": "' . $end_button_text . '", ';
                        }
                    } else {
                        // If user is administrator, moderator or if is viewer and no waiting is required
                        $join_button_text = get_string('view_conference_action_join', 'bigbluebuttonbn');
                        if ($bbbsession['administrator'] || $bbbsession['moderator'] || !$bbbsession['wait']) {
                            $initial_message = get_string('view_message_conference_room_ready', 'bigbluebuttonbn');
                            $can_join = true;
                        } else {
                            $initial_message = get_string('view_message_conference_not_started', 'bigbluebuttonbn');
                            if ($bbbsession['wait']) {
                                $initial_message .= ' ' . get_string('view_message_conference_wait_for_moderator', 'bigbluebuttonbn');
                            }
                            $can_join = false;
                        }

                        if ($bbbsession['tagging'] && ($bbbsession['administrator'] || $bbbsession['moderator'])) {
                            $can_tag = true;

                        } else {
                            $can_tag = false;
                        }
                        $status_can_end = '"can_tag": ' . ($can_tag ? 'true' : 'false') . ', ';
                    }

                    echo $params['callback'] . '({ "running": ' . ($meeting_running ? 'true' : 'false') . ', "info": ' . json_encode($meeting_info) . ', "status": {"can_join": ' . ($can_join ? 'true' : 'false') . ',"join_url": "' . $bbbsession['joinURL'] . '","join_button_text": "' . $join_button_text . '", ' . $status_can_end . $status_can_tag . '"message": "' . $initial_message . '"} });';
                    break;
                case 'meeting_end':
                    if ($bbbsession['administrator'] || $bbbsession['moderator']) {
                        //Execute the end command
                        bigbluebuttonbn_bbb_broker_do_end_meeting($params['id'], $bbbsession['modPW']);
                        // Moodle event logger: Create an event for meeting ended
                        if (isset($bigbluebuttonbn)) {
                            bigbluebuttonbn_event_log(BIGBLUEBUTTON_EVENT_MEETING_ENDED, $bigbluebuttonbn, $context, $cm);
                        }
                        // Update the cache
                        bigbluebuttonbn_bbb_broker_get_meeting_info($params['id'], $bbbsession['modPW'], true);

                        echo $params['callback'] . '({ "status": true });';
                    } else {
                        error_log("ERROR: User not authorized to execute end command");
                        header("HTTP/1.0 401 Unauthorized. User not authorized to execute end command");
                    }
                    break;
                case 'recording_links':
                    if ($bbbsession['managerecordings']) {
                        if (isset($params['id']) && $params['id'] != '') {
                            $recordings_imported_all = bigbluebuttonbn_get_recording_imported_instances($params['id']);
                            echo $params['callback'] . '({ "status": "true", "links": ' . count($recordings_imported_all) . '});';
                        } else {
                            echo $params['callback'] . '({"status": "false"});';
                        }
                    } else {
                        error_log("ERROR: User not authorized to execute end command");
                        header("HTTP/1.0 401 Unauthorized. User not authorized to execute end command");
                    }
                    break;
                case 'recording_info':
                    if ($bbbsession['managerecordings']) {
                        //Retrieve the array of imported recordings
                        $recordings = bigbluebuttonbn_get_recordings($bbbsession['course']->id, $showroom ? $bbbsession['bigbluebuttonbn']->id : null, $showroom, $bbbsession['bigbluebuttonbn']->recordings_deleted_activities);
                        if (isset($recordings[$params['id']])) {
                            // Look up for an update on the imported recording
                            $recording = $recordings[$params['id']];
                            if (isset($recording) && !empty($recording) && !array_key_exists('messageKey', $recording)) {  // The recording was found
                                echo $params['callback'] . '({ "status": "true", "published": "' . $recording['published'] . '"});';
                            } else {
                                echo $params['callback'] . '({ "status": "false" });';
                            }
                        // As the recordingid was not identified as imported recording link, look up for a real recording
                        } else {
                            $recording = bigbluebuttonbn_getRecordingsArray($params['idx'], $params['id']);
                            if (isset($recording) && !empty($recording) && array_key_exists($params['id'], $recording)) {  // The recording was found
                                echo $params['callback'] . '({ "status": "true", "published": "' . $recording[$params['id']]['published'] . '"});';
                            } else {
                                echo $params['callback'] . '({"status": "false"});';
                            }
                        }
                    } else {
                        error_log("ERROR: User not authorized to execute end command");
                        header("HTTP/1.0 401 Unauthorized. User not authorized to execute end command");
                    }
                    break;
                case 'recording_publish':
                case 'recording_unpublish':
                case 'recording_delete':
                    if ($bbbsession['managerecordings']) {
                        $status = true;
                        // Retrieve array of recordings that includes real and imported
                        $recordings = bigbluebuttonbn_get_recordings($bbbsession['course']->id, $showroom ? $bbbsession['bigbluebuttonbn']->id : null, $showroom, $bbbsession['bigbluebuttonbn']->recordings_deleted_activities);
                        switch (strtolower($params['action'])) {
                            case 'recording_publish':
                                if (isset($recordings[$params['id']]) && isset($recordings[$params['id']]['imported'])) {
                                    // Execute publish on imported recording link, if the real recording is published
                                    $real_recordings = bigbluebuttonbn_getRecordingsArray($recordings[$params['id']]['meetingID'], $recordings[$params['id']]['recordID']);
                                    if ($real_recordings[$params['id']]['published'] === 'true') {
                                        // Only if the physical recording is published, execute publish on imported recording link
                                        bigbluebuttonbn_bbb_broker_do_publish_recording_imported($params['id'], $bbbsession['course']->id, $bbbsession['bigbluebuttonbn']->id, true);
                                    } else {
                                        // Send a message telling that it could not be published
                                        $status = false;
                                    }
                                } else {
                                    // As the recordingid was not identified as imported recording link, execute publish on a real recording
                                    bigbluebuttonbn_bbb_broker_do_publish_recording($params['id'], true);
                                }

                                if ($status) {
                                    $callback_response['status'] = "true";
                                    // Moodle event logger: Create an event for recording published
                                    if (isset($bigbluebuttonbn)) {
                                        bigbluebuttonbn_event_log(BIGBLUEBUTTON_EVENT_RECORDING_PUBLISHED, $bigbluebuttonbn, $context, $cm);
                                    }
                                } else {
                                    $callback_response['status'] = "false";
                                    $callback_response['message'] = get_string('view_recording_publish_link_error', 'bigbluebuttonbn');
                                }
                                break;
                            case 'recording_unpublish':
                                if (isset($recordings[$params['id']]) && isset($recordings[$params['id']]['imported'])) {
                                    // Execute unpublish on imported recording link
                                    bigbluebuttonbn_bbb_broker_do_publish_recording_imported($params['id'], $bbbsession['course']->id, $bbbsession['bigbluebuttonbn']->id, false);
                                } else {
                                    // As the recordingid was not identified as imported recording link, execute unpublish on a real recording
                                    // First: Unpublish imported links associated to the recording
                                    $recordings_imported_all = bigbluebuttonbn_get_recording_imported_instances($params['id']);

                                    if ($recordings_imported_all > 0) {
                                        foreach ($recordings_imported_all as $key => $record) {
                                            $meta = json_decode($record->meta, true);
                                            // Prepare data for the update
                                            $meta['recording']['published'] = 'false';
                                            $recordings_imported_all[$key]->meta = json_encode($meta);

                                            // Proceed with the update
                                            $DB->update_record("bigbluebuttonbn_logs", $recordings_imported_all[$key]);
                                        }
                                    }
                                    // Second: Execute the real unpublish
                                    bigbluebuttonbn_bbb_broker_do_publish_recording($params['id'], false);
                                }

                                $callback_response['status'] = "true";
                                // Moodle event logger: Create an event for recording unpublished
                                if (isset($bigbluebuttonbn)) {
                                    bigbluebuttonbn_event_log(BIGBLUEBUTTON_EVENT_RECORDING_UNPUBLISHED, $bigbluebuttonbn, $context, $cm);
                                }
                                break;
                            case 'recording_delete':
                                if (isset($recordings[$params['id']]) && isset($recordings[$params['id']]['imported'])) {
                                    // Execute delete on imported recording link
                                    bigbluebuttonbn_bbb_broker_do_delete_recording_imported($params['id'], $bbbsession['course']->id, $bbbsession['bigbluebuttonbn']->id);
                                } else {
                                    // As the recordingid was not identified as imported recording link, execute delete on a real recording
                                    // First: Delete imported links associated to the recording
                                    $recordings_imported_all = bigbluebuttonbn_get_recording_imported_instances($params['id']);

                                    if ($recordings_imported_all > 0) {
                                        foreach ($recordings_imported_all as $key => $record) {
                                            // Execute delete
                                            $DB->delete_records("bigbluebuttonbn_logs", array('id' => $key));
                                        }
                                    }
                                    // Second: Execute the real delete
                                    bigbluebuttonbn_bbb_broker_do_delete_recording($params['id']);
                                }

                                $callback_response['status'] = "true";
                                // Moodle event logger: Create an event for recording deleted
                                if (isset($bigbluebuttonbn)) {
                                    bigbluebuttonbn_event_log(BIGBLUEBUTTON_EVENT_RECORDING_DELETED, $bigbluebuttonbn, $context, $cm);
                                }
                                break;
                        }

                        $callback_response_data = json_encode($callback_response);
                        echo "{$params['callback']}({$callback_response_data});";
                    } else {
                        error_log("ERROR: User not authorized to execute publish command");
                        header("HTTP/1.0 401 Unauthorized. User not authorized to execute publish command");
                    }
                    break;
                case 'recording_ready':
                    //Decodes the received JWT string
                    try {
                        $decoded_parameters = JWT::decode($params['signed_parameters'], $shared_secret, array('HS256'));

                    } catch (Exception $e) {
                        $error = 'Caught exception: ' . $e->getMessage();
                        error_log($error);
                        header("HTTP/1.0 400 Bad Request. " . $error);
                        return;
                    }

                    // Validate that the bigbluebuttonbn activity corresponds to the meeting_id received
                    $meeting_id_elements = explode("[", $decoded_parameters->meeting_id);
                    $meeting_id_elements = explode("-", $meeting_id_elements[0]);
                    if (isset($bigbluebuttonbn) && $bigbluebuttonbn->meetingid == $meeting_id_elements[0]) {
                        // Sends the messages
                        try {
                            bigbluebuttonbn_send_notification_recording_ready($bigbluebuttonbn);
                            header("HTTP/1.0 202 Accepted");
                            return;
                        } catch (Exception $e) {
                            $error = 'Caught exception: ' . $e->getMessage();
                            error_log($error);
                            header("HTTP/1.0 503 Service Unavailable. " . $error);
                            return;
                        }

                    } else {
                        $error = 'Caught exception: ' . $e->getMessage();
                        error_log($error);
                        header("HTTP/1.0 410 Gone. " . $error);
                        return;
                    }

                    break;
                case 'recording_import':
                    if ($bbbsession['managerecordings']) {
                        $importrecordings = $SESSION->bigbluebuttonbn_importrecordings;
                        if (isset($importrecordings[$params['id']])) {
                            $importrecordings[$params['id']]['imported'] = true;
                            $overrides['meetingid'] = $importrecordings[$params['id']]['meetingID'];
                            $meta = '{"recording":' . json_encode($importrecordings[$params['id']]) . '}';
                            bigbluebuttonbn_logs($bbbsession, BIGBLUEBUTTONBN_LOG_EVENT_IMPORT, $overrides, $meta);
                            // Moodle event logger: Create an event for recording imported
                            if (isset($bigbluebuttonbn)) {
                                bigbluebuttonbn_event_log(BIGBLUEBUTTON_EVENT_RECORDING_IMPORTED, $bigbluebuttonbn, $context, $cm);
                            }

                            $callback_response['status'] = "true";
                            $callback_response_data = json_encode($callback_response);
                            echo "{$params['callback']}({$callback_response_data});";

                        } else {
                            $error = "Recording {$params['id']} could not be found. It can not be imported";
                            error_log($error);
                            header("HTTP/1.0 404 Not found. " . $error);
                            return;
                        }
                    }
                    break;
                case 'meeting_events':
                    //Decodes the received JWT string
                    try {
                        $decoded_parameters = JWT::decode($params['signed_parameters'], $shared_secret, array('HS256'));

                    } catch (Exception $e) {
                        $error = 'Caught exception: ' . $e->getMessage();
                        error_log($error);
                        header("HTTP/1.0 400 Bad Request. " . $error);
                        return;
                    }

                    // Validate that the bigbluebuttonbn activity corresponds to the meeting_id received
                    $meeting_id_elements = explode("[", $decoded_parameters->meeting_id);
                    $meeting_id_elements = explode("-", $meeting_id_elements[0]);
                    if (isset($bigbluebuttonbn) && $bigbluebuttonbn->meetingid == $meeting_id_elements[0]) {
                        // Store the events
                        try {
                            error_log("We start storing the events here");
                            foreach ($decoded_parameters->events as $event) {
                                error_log($event->event);
                                bigbluebuttonbn_meeting_event_log($event, $bigbluebuttonbn, $context, $cm);
                            }
                            //bigbluebuttonbn_send_notification_recording_ready($bigbluebuttonbn);
                            header("HTTP/1.0 202 Accepted");
                            return;
                        } catch (Exception $e) {
                            $error = "Caught exception: {$e->getMessage()}";
                            error_log($error);
                            header("HTTP/1.0 503 Service Unavailable. {$error}");
                            return;
                        }

                    } else {
                        $error = "Activity with meetingID '{$meeting_id_elements[0]}' was not found";
                        error_log($error);
                        header("HTTP/1.0 410 Gone. {$error}");
                        return;
                    }
                    break;
                case 'moodle_event':
                    break;
            }

        } catch (Exception $e) {
            error_log("BBB_BROKER ERROR: " . $e->getCode() . ", " . $e->getMessage());
            header("HTTP/1.0 502 Bad Gateway. " . $e->getMessage());
            return;
        }
    }

} else {
    error_log(json_encode($error));
    header("HTTP/1.0 400 Bad Request. " . $error);
    return;
}
