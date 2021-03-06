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
 * The mod_bigbluebuttonbn opencast helper
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2021 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Farbod Zamani  (zamani [at] elan-ev [dt] de)
 */

namespace mod_bigbluebuttonbn\helpers;

use moodle_url;
use stdClass;
use core_plugin_manager;
use html_writer;
use dml_exception;
use moodle_exception;
use oauth_helper;
use ReflectionMethod;
use html_table;
use html_table_row;

defined('MOODLE_INTERNAL') || die();

/**
 * Utility class for Opencast helper
 *
 * @package mod_bigbluebuttonbn
 * @copyright 2021 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class opencast {

    /**
     * Helper function which checks if the Opencast plugin (block_opencast) is installed. The function is called from several places throughout mod_bigbluebuttonbn where Opencast functionality can enhance the BBB meeting recording functionality as soon as the Opencast plugin is present.
     * If called with a course ID as parameter, the function will not only check if the Opencast plugin is installed. It will also ensure that an Opencast series exists for the given course and will return the Opencast series ID instead of a boolean. In this case, the block does not necessarily be placed in the course.
     *
     * @param  string    $courseid
     * @return boolean|string
     */
    public static function bigbluebuttonbn_check_opencast($courseid = null) {
        $blockplugins = core_plugin_manager::instance()->get_plugins_of_type('block');
        // If block_opencast is installed.
        if (in_array('opencast', array_keys($blockplugins))) {
            // If a courseid is given, we will return the Opencast series ID for the course.
            if (is_numeric($courseid) && $courseid > 0) {
                // Getting an instance of the block_opencast API bridge.
                $opencast = self::bigbluebuttonbn_get_opencast_apibridge_instance();
                if (!$opencast) {
                    return false;
                }

                // Get and return the Opencast series ID for the given course. Let Opencast create a new series if there isn't a series yet for this course.
                // If an exception occurs during this process, return false as the Opencast integration is not usable at the moment which is the same as if the Opencast plugin would not be installed at all.
                try {
                    // Use get_stored_seriesid method in order to retreive the series id, but when the method accepts more than 1 parameter which is introduced in v3.11-r1 of block_opencast.
                    $getstoredseriesidreflection = new ReflectionMethod($opencast, 'get_stored_seriesid');
                    if (count($getstoredseriesidreflection->getParameters()) > 1) {
                        // The second parameter ($createifempty = true) of this method helps to create the series when it does not exist.
                        $series = $opencast->get_stored_seriesid($courseid, true);
                    } else {
                        // If get_stored_seriesid accepts only 1 argument, then we use another method called ensure_course_series_exists. Mostly used for older versions of block_opencast plugin.
                        // To make sure that the ensure_course_series_exists method accepts all the parameters it needs, we use ReflectionMethod and call_user_func_array.
                        $ensurecourseseriesexistsreflection = new ReflectionMethod($opencast, 'ensure_course_series_exists');
                        // Filling up an array with null value based on parameters' count of ensure_course_series_exists method.
                        $args = array_fill(0, count($ensurecourseseriesexistsreflection->getParameters()), null);
                        // Replace the first element of args array with courseid
                        $args[0] = $courseid;
                        $series = call_user_func_array([$opencast, 'ensure_course_series_exists'], $args);
                    }
                    if (is_object($series) && $series->identifier) {
                        $seriesid = $series->identifier;
                    } else {
                        $seriesid = $series;
                    }
                    return $seriesid;
                } catch (Exception $e) {
                    return false;
                }
            }
            // The block_opencast plugin is installed.
            return true;
        }
        // If block_opencast plugin is NOT installed.
        return false;
    }

    /**
     * Helper function to validate the opencast instance and return the apibridge instance.
     * 
     * @return bool | \block_opencast\local\apibridge | 
     */
    private static function bigbluebuttonbn_get_opencast_apibridge_instance() {
        try {
            $bbbocinstance = \mod_bigbluebuttonbn\locallib\config::get('opencast_instance');
            $bbbocinstance = intval($bbbocinstance) + 1;
            // check the Opencast Version compatibility.
            if (!class_exists('\tool_opencast\local\settings_api') || !method_exists('\tool_opencast\local\settings_api', 'get_ocinstances')) {
                // We proceed without instance.
                $api = new \tool_opencast\local\api();
                $url = '/api/info/me';
                $info = $api->oc_get($url);
                if ($api->get_http_code() != 200) {
                    return false;
                }

                $opencast = \block_opencast\local\apibridge::get_instance();
                // If the block_opencast API bridge is not configured.
                if (!$opencast) {
                    return false;
                }

                return $opencast;
            }

            $ocinstances = \tool_opencast\local\settings_api::get_ocinstances();
            if (!in_array($bbbocinstance, array_column($ocinstances, 'id'))) {
                return false;
            }

            $api = new \tool_opencast\local\api($bbbocinstance);
            $url = '/api/info/me';
            $info = $api->oc_get($url);
            if ($api->get_http_code() != 200) {
                return false;
            }

            $opencast = \block_opencast\local\apibridge::get_instance($bbbocinstance);
            // If the block_opencast API bridge is not configured.
            if (!$opencast) {
                return false;
            }
            
            return $opencast;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Helper function renders Opencast integration settings if block_opencast is installed.
     *
     *
     * @return void
     */
    public static function bigbluebuttonbn_settings_opencast_integration(&$renderer) {
        // Configuration for 'Opencast integration' feature when Opencast plugins are installed.
        if ((boolean) self::bigbluebuttonbn_check_opencast()) {
            $renderer->render_group_header('opencast');
            $renderer->render_group_element(
                'opencast_recording',
                $renderer->render_group_element_checkbox('opencast_recording', 0)
            );
            
            // Get Opencast Instances.
            $choices = self::bigbluebuttonbn_get_ocinstances();
            $default = self::bigbluebuttonbn_get_default_ocinstance();
            $renderer->render_group_element(
                'opencast_instance',
                $renderer->render_group_element_configselect(
                    'opencast_instance',
                    $default,
                    $choices
                )
            );
            $renderer->render_group_element(
                'opencast_show_recording',
                $renderer->render_group_element_checkbox('opencast_show_recording', 0)
            );
            $renderer->render_group_element(
                'opencast_lti_consumerkey',
                $renderer->render_group_element_text('opencast_lti_consumerkey')
            );
            $renderer->render_group_element(
                'opencast_lti_consumersecret',
                $renderer->render_group_element_text('opencast_lti_consumersecret')
            );
            $renderer->render_group_element(
                'opencast_engageurl',
                $renderer->render_group_element_text('opencast_engageurl')
            );
            $renderer->render_group_element(
                'opencast_playerurl',
                $renderer->render_group_element_text('opencast_playerurl')
            );
        }
    }

    /**
     * Helper function to get all the configured opencast instances.
     *
     *
     * @return array
     */
    private static function bigbluebuttonbn_get_ocinstances() {
        // check the Opencast Version compatibility.
        if (!class_exists('\tool_opencast\local\settings_api') || !method_exists('\tool_opencast\local\settings_api', 'get_ocinstances')) {
            return ['0' => 'Default'];
        }
        $ocinstancenames = [];
        $ocinstances = \tool_opencast\local\settings_api::get_ocinstances();
        if (empty($ocinstances)) {
            return $ocinstancenames;
        }
        foreach ($ocinstances as $ocinstanceconfig) {
            $name = (!empty($ocinstanceconfig->name)) ? $ocinstanceconfig->name : $ocinstanceconfig->id;
            $ocinstancenames[($ocinstanceconfig->id - 1)] = $name;
        }
        return $ocinstancenames;
    }

    /**
     * Helper function to get the index of the default configured opencast instances.
     *
     *
     * @return array
     */
    private static function bigbluebuttonbn_get_default_ocinstance() {
        // check the Opencast Version compatibility.
        if (!class_exists('\tool_opencast\local\settings_api') || !method_exists('\tool_opencast\local\settings_api', 'get_default_ocinstance')) {
            return 0;
        }
        $ocdefaultinstance = \tool_opencast\local\settings_api::get_default_ocinstance();
        if (empty($ocdefaultinstance)) {
            return 0;
        }
        return ($ocdefaultinstance->id - 1);
    }

    /**
     * Helper function to get BBB recordings from the Opencast video available in the course.
     * It uses block_opencast for getting all videos for the course and match them with meeting id.
     * It uses tool_opencast for making an api call to get mediapackage of vidoes.
     *
     * @param object $bbbsession
     *
     *  @return array $bbbocvideos Opencast recordings of the BBB session
     */
    public static function bigbluebutton_get_opencast_recordings_for_table_view($bbbsession) {
        $bbbocvideos = [];
        // Getting an instance of apibridge from block_opencast plugin.
        $opencast = self::bigbluebuttonbn_get_opencast_apibridge_instance();
        if (!$opencast) {
            return [];
        }
        // Initializing the api from tool_opencast plugin.
        $bbbocinstance = \mod_bigbluebuttonbn\locallib\config::get('opencast_instance');
        $bbbocinstance = intval($bbbocinstance) + 1;
        $api = new \tool_opencast\local\api($bbbocinstance);
        // Getting the course videos from block_opencast plugin.
        $ocvideos = $opencast->get_course_videos($bbbsession['course']->id);
        if ($ocvideos->error == 0) {
            foreach ($ocvideos->videos as $ocvideo) {
                // Check subjects of opencast video contains $bbbsession['meetingid'].
                if (in_array($bbbsession['meetingid'], $ocvideo->subjects)) {
                    // Converting $ocvideo object to array.
                    $ocvideoarray = json_decode(json_encode($ocvideo), true);
                    // Get mediapackage json using api call.
                    $url = '/search/episode.json?id=' . $ocvideo->identifier;
                    $search_result = json_decode($api->oc_get($url), true);
                    if ($api->get_http_code() == 200 && isset($search_result['search-results']['result']['mediapackage'])) {
                        // Add mediapackage to array if exists.
                        $ocvideoarray['mediapackage'] = $search_result['search-results']['result']['mediapackage'];
                    }
                    $bbbocvideos[] = $ocvideoarray;
                }
            }
        }
        return $bbbocvideos;
    }

    /**
     * Helper function builds a row for the data used by the Opencast recording table.
     *
     * @param array $bbbsession
     * @param array $ocrecording
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_opencast_recording_data_row($bbbsession, $ocrecording, $tools = ['edit', 'delete']) {
        if (!self::bigbluebuttonbn_include_opencast_recording_table_row($bbbsession)) {
            return;
        }
        $rowdata = new stdClass();
        // Set recording playback url.
        $rowdata->playback = self::bigbluebuttonbn_get_opencast_recording_data_row_playback($ocrecording, $bbbsession);
        // Set recording name from title if exists, otherwise shows "Opencast Video".
        $rowdata->name = isset($ocrecording['title']) ? $ocrecording['title'] : get_string('view_recording_list_opencast', 'bigbluebuttonbn');
        // Set recording description.
        $rowdata->description = isset($ocrecording['description']) ? $ocrecording['description'] : '';
        // For Opencast recording table to maintain the consistency, it checks if preview is enabled for the recording table.
        // if (recording::bigbluebuttonbn_get_recording_data_preview_enabled($bbbsession)) {
        // Set recording_preview.
        $rowdata->preview = self::bigbluebuttonbn_get_opencast_recording_data_row_preview($ocrecording);
        // }
        // Set formatted date.
        $rowdata->date = self::bigbluebuttonbn_get_opencast_recording_data_row_date_formatted($ocrecording);
        // Set formatted duration.
        $rowdata->duration = self::bigbluebuttonbn_get_opencast_recording_data_row_duration($ocrecording['duration']);
        // Set actionbar, if user is allowed to manage recordings.
        if ($bbbsession['managerecordings']) {
            $rowdata->actionbar = self::bigbluebuttonbn_get_opencast_recording_data_row_actionbar($ocrecording, $bbbsession, $tools);
        }
        return $rowdata;
    }

    /**
     * Helper function evaluates if Opencast recording row should be included in the table.
     *
     * @param array $bbbsession
     *
     * @return boolean
     */
    public static function bigbluebuttonbn_include_opencast_recording_table_row($bbbsession) {
        // Administrators and moderators are always allowed.
        if ($bbbsession['administrator'] || $bbbsession['moderator']) {
            return true;
        }
        // When groups are enabled, exclude those to which the user doesn't have access to.
        // Check if the record belongs to a Visible Group type.
        list($course, $cm) = get_course_and_cm_from_cmid($bbbsession['cm']->id);
        $groupmode = groups_get_activity_groupmode($cm);
        $displayrow = true;
        if (($groupmode != VISIBLEGROUPS)) {
            $groupid = explode('[', $bbbsession['meetingid']);
            if (isset($groupid[1])) {
                // It is a group recording and the user is not moderator/administrator. Recording should not be included by default.
                $displayrow = false;
                $groupid = explode(']', $groupid[1]);
                if (isset($groupid[0])) {
                    foreach ($usergroups as $usergroup) {
                        if ($usergroup->id == $groupid[0]) {
                            // Include recording if the user is in the same group.
                            $displayrow = true;
                        }
                    }
                }
            }
        }
        return $displayrow;
    }

    /**
     * Helper function renders the link used for Opencast recording playback in row for the data used by the recording table.
     * To display the video, it is important for a video in Opencast to be published with engage-player, also it is required to
     * have filter_opencast plugin installed and configured. 
     * The link redirects user to oc_view.php to authentificate the user via LTI and show the video in Opencast. 
     *
     * @param array $ocrecording
     * @param array $bbbsession
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_opencast_recording_data_row_playback($ocrecording, $bbbsession) {
        global $CFG, $OUTPUT;
        $text = get_string('view_recording_list_opencast', 'bigbluebuttonbn');
        $href = '#';
        // Check if the publication status has engage-player.
        if (isset($ocrecording['publication_status']) && in_array('engage-player', $ocrecording['publication_status'])) {
            // If lti settings are set,
            // also if the LTI form handler JavaScript file in block_opencast is available to use in order to submit the LTI form.
            if ((boolean) self::bigbluebuttonbn_check_opencast_lti_data() && file_exists("$CFG->dirroot/blocks/opencast/amd/src/block_lti_form_handler.js")) {
                $href = $CFG->wwwroot . '/mod/bigbluebuttonbn/oc_player.php?identifier=' . $ocrecording['identifier'] .
                '&bn=' . $bbbsession['bigbluebuttonbn']->id;
            }
        }

        $linkattributes = array(
            'id' => 'opencast-player-redirect-' . $ocrecording['identifier'],
            'class' => 'btn btn-sm btn-secondary',
            'target' => '_blank'
        );
        if ($href == '#' || empty($href)) {
            unset($linkattributes['target']);
            $linkattributes['class'] = 'btn btn-sm btn-warning';
            $linkattributes['title'] = get_string('view_recording_format_error_opencast_unreachable', 'bigbluebuttonbn');
        }
        return $OUTPUT->action_link($href, $text, null, $linkattributes) . '&#32;';
    }

    /**
     * Helper function builds Opencast recording preview used in row for the data used by the recording table.
     *
     * @param array $ocrecording
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_opencast_recording_data_row_preview($ocrecording) {
        $options = array('id' => 'preview-' . $ocrecording['identifier']);
        $recordingpreview = html_writer::start_tag('div', $options);
        $imageurl = '';
        // Getting preview image from mediapackage attachments.
        if (isset($ocrecording['mediapackage']['attachments']['attachment'])) {
            foreach ($ocrecording['mediapackage']['attachments']['attachment'] as $attachment) {
                // Looking for image only.
                if (isset($attachment['mimetype']) && strpos($attachment['mimetype'], 'image') !== FALSE) {
                    // Looking for the url of the preview image.
                    if (empty($imageurl) && isset($attachment['type']) && isset($attachment['url'])) {
                        // There are several type of attachments which are different in size.
                        // More suitable sizes are of these types, respectively.
                        $suitabletypes = array('search', 'feed');
                        foreach ($suitabletypes as $type) {
                            if (strpos($attachment['type'], $type) !== FALSE) {
                                $imageurl = $attachment['url'];
                                break;
                            }
                        }
                    }
                }
                if (!empty($imageurl)) {
                    break;
                }
            }
            if (!empty($imageurl)) {
                $recordingpreview .= self::bigbluebuttonbn_get_opencast_recording_data_row_preview_images($imageurl);
            }
        }
    
        $recordingpreview .= html_writer::end_tag('div');
        return $recordingpreview;
    }

    /**
     * Helper function builds element with actual images used in Opencast recording preview row based on a selected playback.
     *
     * @param string $imageurl
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_opencast_recording_data_row_preview_images($imageurl) {
        global $CFG;
        $recordingpreview  = html_writer::start_tag('div', array('class' => 'container-fluid'));
        $recordingpreview .= html_writer::start_tag('div', array('class' => 'row'));
        $recordingpreview .= html_writer::start_tag('div', array('class' => ''));
        $recordingpreview .= html_writer::empty_tag(
            'img',
            array('src' => trim($imageurl) . '?' . time(), 'class' => 'recording-thumbnail pull-left')
        );
        $recordingpreview .= html_writer::end_tag('div');
        $recordingpreview .= html_writer::end_tag('div');
        $recordingpreview .= html_writer::start_tag('div', array('class' => 'row'));
        $recordingpreview .= html_writer::tag(
            'div',
            get_string('view_recording_preview_help', 'bigbluebuttonbn'),
            array('class' => 'text-center text-muted small')
        );
        $recordingpreview .= html_writer::end_tag('div');
        $recordingpreview .= html_writer::end_tag('div');
        return $recordingpreview;
    }

    /**
     * Helper function format Opencast recording date used in row for the data used by the recording table.
     *
     * @param array $ocrecording
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_opencast_recording_data_row_date_formatted($ocrecording) {
        $starttime_str = !empty($ocrecording['start']) ? $ocrecording['start'] : $ocrecording['created'];
        return userdate(strtotime($starttime_str), get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Helper function converts Opencast recording duration used in row for the data used by the recording table.
     *
     * @param array $duration
     *
     * @return integer
     */
    public static function bigbluebuttonbn_get_opencast_recording_data_row_duration($duration) {
        if ($duration) {
            // Convert the duration (in miliseconds) into Hours:Minutes:Seconds format
            return gmdate('H:i:s', $duration / 1000);
        }
        return 0;
    }

    /**
     * Helper function builds Opencast recording actionbar used in row for the data used by the recording table.
     *
     * @param array $ocrecording
     * @param array $bbbsession
     * @param array $tools
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_opencast_recording_data_row_actionbar($ocrecording, $bbbsession, $tools) {
        global $OUTPUT;
        if (empty($ocrecording['identifier']) || empty($bbbsession['course'])) {
            return '';
        }
        $actionbar = '';
        $linkattributes = array(
            'target' => '_blank',
            'class' => 'btn btn-xs btn-danger'
        );
        if (in_array('edit', $tools)) {
            // Creating moodle url, to redirect to Opencast update metadata (Edit) page.
            $opencastediturl = new moodle_url('/blocks/opencast/updatemetadata.php',
                    array('video_identifier' => $ocrecording['identifier'], 'courseid' => $bbbsession['course']->id));
            $linkattributes['id'] = 'opencast-edit-episode-' . $ocrecording['identifier'];
            // Generating Action Link for Opencast update metadata (Edit).
            $actionbar .= $OUTPUT->action_link($opencastediturl, get_string('edit'), null, $linkattributes) . '&#32;';
        }
        
        if (in_array('delete', $tools)) {
            // Creating moodle url, to redirect to Opencast delete event (Delete) page.
            $opencastdeleteurl = new moodle_url('/blocks/opencast/deleteevent.php',
                    array('identifier' => $ocrecording['identifier'], 'courseid' => $bbbsession['course']->id));
            $linkattributes['id'] = 'opencast-delete-episode-' . $ocrecording['identifier'];
            // Generating Action Link for Opencast delete event (Delete).
            $actionbar .= $OUTPUT->action_link($opencastdeleteurl, get_string('delete'), null, $linkattributes) . '&#32;';
        }
        $head = html_writer::start_tag('div', array(
            'id' => 'recording-actionbar-' . $ocrecording['identifier'],
            'data-recordingid' => $ocrecording['identifier'],
            'data-meetingid' => $bbbsession['meetingid']));
        $tail = html_writer::end_tag('div');
        return $head . $actionbar . $tail;
    }

    /**
     * Helper function to gather all the required data to make LTI auth in order to show the videos separately.
     * 
     * @return boolean|array
     */
    public static function bigbluebuttonbn_check_opencast_lti_data() {

        $opencast = self::bigbluebuttonbn_get_opencast_apibridge_instance();
        if (!$opencast) {
            return false;
        }
        $bbbocinstance = \mod_bigbluebuttonbn\locallib\config::get('opencast_instance');
        $bbbocinstance = intval($bbbocinstance) + 1;

        $consumerkey = \mod_bigbluebuttonbn\locallib\config::get('opencast_lti_consumerkey');
        $consumersecret = \mod_bigbluebuttonbn\locallib\config::get('opencast_lti_consumersecret');
        $engageurl = \mod_bigbluebuttonbn\locallib\config::get('opencast_engageurl');
        if (empty($engageurl)) {
            // If it is not set in filter_opencast, the main apiurl setting of tool_opencast plugin will be used.
            $instanceapiurl = ($bbbocinstance > 1) ? "apiurl_$bbbocinstance" : 'apiurl';
            $engageurl = get_config('tool_opencast', $instanceapiurl);
        }

        if (strpos($engageurl, 'http') !== 0) {
            $engageurl = 'http://' . $engageurl;
        }

        // A player url helps to predefine the endpoint to call the Opencast player directly.
        $playerurl = \mod_bigbluebuttonbn\locallib\config::get('opencast_playerurl');
        if (empty($playerurl)) {
            // If it is not configured, /play/ endpoint will make Opencast to decide based on its internal configuration.
            $playerurl = '/play/';
        } else {
            // If it is configured, then "id" query string is needed in any case.
            $playerurl .= '?id=';
        }

        // Make sure the player url is correct.
        $playerurl = '/' . ltrim($playerurl, '/');

        // Make lti url.
        $ltiendpoint = rtrim($engageurl, '/') . '/lti';

        if (!empty($consumerkey) && !empty($consumersecret) && !empty($playerurl) && !empty($ltiendpoint)) {
            // If filter_opencast plugin is configured.
            return array(
                'consumerkey' => $consumerkey,
                'consumersecret' => $consumersecret,
                'engageurl' => $engageurl,
                'playerurl' => $playerurl,
                'ltiendpoint' => $ltiendpoint
            );
        } else {
            // If filter_opencast plugin is NOT configured.
            return false;
        }
    }

    /**
     * Renders the view for recordings.
     *
     * @param array $bbbsession
     * @param integer $type
     * @param array $enabledfeatures
     * @param array $jsvars
     * @return string
     */
    public static function bigbluebuttonbn_view_opencast_render_recording_section($bbbsession, $type) {
        if ($type == BIGBLUEBUTTONBN_TYPE_ROOM_ONLY) {
            return '';
        }
        $output = '';
        if ($type == BIGBLUEBUTTONBN_TYPE_ALL && $bbbsession['record']) {
            $output .= html_writer::start_tag('div', array('id' => 'bigbluebuttonbn_view_opencast_recordings_header'));
            $output .= html_writer::tag('h4', get_string('view_section_title_opencast_recordings', 'bigbluebuttonbn'));
            $output .= html_writer::end_tag('div');
        }
        if ($type == BIGBLUEBUTTONBN_TYPE_RECORDING_ONLY || $bbbsession['record']) {
            $output .= html_writer::start_tag('div', array('id' => 'bigbluebuttonbn_view_opencast_recordings_content'));
            $output .= self::bigbluebuttonbn_view_opencast_render_recordings($bbbsession);
            $output .= html_writer::end_tag('div');
        }
        return $output;
    }

    /**
     * Helper function renders recording table.
     *
     * @param array $bbbsession
     * @param array $recordings
     * @param array $tools
     *
     * @return array
     */
    public static function bigbluebuttonbn_output_opencast_recording_table($bbbsession, $ocrecordings) {
        if (isset($ocrecordings) && !empty($ocrecordings)) {
            // There are recordings for this meeting.
            $table = self::bigbluebuttonbn_get_opencast_recording_table($bbbsession, $ocrecordings);
        }
        if (!isset($table) || !isset($table->data)) {
            // Render a table with "No recordings".
            return html_writer::div(
                get_string('view_message_opencast_norecordings', 'bigbluebuttonbn'),
                '',
                array('id' => 'bigbluebuttonbn_opencast_recordings_table')
            ) . '<br />';
        }
        // Render the table.
        return html_writer::div(html_writer::table($table), '', array('id' => 'bigbluebuttonbn_opencast_recordings_table'));
    }

    /**
     * Renders the view for recordings.
     *
     * @param array $bbbsession
     *
     * @return string
     */
    public static function bigbluebuttonbn_view_opencast_render_recordings($bbbsession) {
        // Get Opencast Recordings for this BBB session.
        $ocrecordings = self::bigbluebutton_get_opencast_recordings_for_table_view($bbbsession);

        // Output the opencast recording table.
        $output = self::bigbluebuttonbn_output_opencast_recording_table($bbbsession, $ocrecordings)."\n";

        return $output;
    }


    /**
     * Helper function builds the recording table.
     *
     * @param array $bbbsession
     * @param array $recordings
     * @param array $tools
     *
     * @return object
     */
    public static function bigbluebuttonbn_get_opencast_recording_table($bbbsession, $ocrecordings) {
        global $DB;
        // Declare the table.
        $table = new html_table();
        $table->data = array();
        // Initialize table headers.
        $table->head[] = get_string('view_recording_playback', 'bigbluebuttonbn');
        $table->head[] = get_string('view_recording_name', 'bigbluebuttonbn');
        $table->head[] = get_string('view_recording_description', 'bigbluebuttonbn');
        $table->head[] = get_string('view_recording_preview', 'bigbluebuttonbn');
        $table->head[] = get_string('view_recording_date', 'bigbluebuttonbn');
        $table->head[] = get_string('view_recording_duration', 'bigbluebuttonbn');
        $table->align = array('left', 'left', 'left', 'left', 'left', 'center');
        $table->size = array('', '', '', '', '', '');
        $table->head[] = get_string('view_recording_actionbar', 'bigbluebuttonbn');
        $table->align[] = 'left';
        $table->size[] = '40px';
        
        // Get the groups of the user.
        $usergroups = groups_get_all_groups($bbbsession['course']->id, $bbbsession['userID']);

        // Build table content.
        foreach ($ocrecordings as $ocrecording) {
            $rowdata = self::bigbluebuttonbn_get_opencast_recording_data_row($bbbsession, $ocrecording);
            if (!empty($rowdata)) {
                $row = self::bigbluebuttonbn_get_opencast_recording_table_row($bbbsession, $ocrecording, $rowdata);
                array_push($table->data, $row);
            }
        }
        return $table;
    }

    /**
     * Helper function builds the recording table row and insert into table.
     *
     * @param array $bbbsession
     * @param array $ocrecording
     * @param object $rowdata
     *
     * @return object
     */
    public static function bigbluebuttonbn_get_opencast_recording_table_row($bbbsession, $ocrecording, $rowdata) {
        $row = new html_table_row();
        $row->id = 'recording-tr-' . $ocrecording['identifier'];
        $row->attributes['data-imported'] = 'false';
        $texthead = '';
        $texttail = '';
        $rowdata->date = str_replace(' ', '&nbsp;', $rowdata->date);
        $row->cells = array();
        $row->cells[] = $texthead . $rowdata->playback . $texttail;
        $row->cells[] = $texthead . $rowdata->name . $texttail;
        $row->cells[] = $texthead . $rowdata->description . $texttail;
        $row->cells[] = $rowdata->preview;
        $row->cells[] = $texthead . $rowdata->date . $texttail;
        $row->cells[] = $rowdata->duration;
        $row->cells[] = $rowdata->actionbar;
        return $row;
    }

    /**
     * Create necessary lti parameters.
     * @param array $opencastfilterconfig the array of customized configuration from bigbluebuttonbn_check_opencast_lti_data function.
     *
     * @return array lti parameters
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function bigbluebuttonbn_create_lti_parameters_opencast($opencastfilterconfig) {
        global $CFG, $COURSE, $USER;

        $endpoint = $opencastfilterconfig['ltiendpoint'];
        $consumerkey = $opencastfilterconfig['consumerkey'];
        $consumersecret = $opencastfilterconfig['consumersecret'];
        $customtool = $opencastfilterconfig['playerurl'];

        $helper = new oauth_helper(array('oauth_consumer_key'    => $consumerkey,
                                        'oauth_consumer_secret' => $consumersecret));

        // Set all necessary parameters.
        $params = array();
        $params['oauth_version'] = '1.0';
        $params['oauth_nonce'] = $helper->get_nonce();
        $params['oauth_timestamp'] = $helper->get_timestamp();
        $params['oauth_consumer_key'] = $consumerkey;

        $params['context_id'] = $COURSE->id;
        $params['context_label'] = trim($COURSE->shortname);
        $params['context_title'] = trim($COURSE->fullname);
        $params['resource_link_id'] = 'o' . random_int(1000, 9999) . '-' . random_int(1000, 9999);
        $params['resource_link_title'] = 'Opencast';
        $params['context_type'] = ($COURSE->format == 'site') ? 'Group' : 'CourseSection';
        $params['launch_presentation_locale'] = current_language();
        $params['ext_lms'] = 'moodle-2';
        $params['tool_consumer_info_product_family_code'] = 'moodle';
        $params['tool_consumer_info_version'] = strval($CFG->version);
        $params['oauth_callback'] = 'about:blank';
        $params['lti_version'] = 'LTI-1p0';
        $params['lti_message_type'] = 'basic-lti-launch-request';
        $urlparts = parse_url($CFG->wwwroot);
        $params['tool_consumer_instance_guid'] = $urlparts['host'];
        $params['custom_tool'] = $customtool;

        // User data.
        $params['user_id'] = $USER->id;
        $params['lis_person_name_given'] = $USER->firstname;
        $params['lis_person_name_family'] = $USER->lastname;
        $params['lis_person_name_full'] = $USER->firstname . ' ' . $USER->lastname;
        $params['ext_user_username'] = $USER->username;
        $params['lis_person_contact_email_primary'] = $USER->email;
        $params['roles'] = lti_get_ims_role($USER, null, $COURSE->id, false);

        if (!empty($CFG->mod_lti_institution_name)) {
            $params['tool_consumer_instance_name'] = trim(html_to_text($CFG->mod_lti_institution_name, 0));
        } else {
            $params['tool_consumer_instance_name'] = get_site()->shortname;
        }

        $params['launch_presentation_document_target'] = 'iframe';
        $params['oauth_signature_method'] = 'HMAC-SHA1';
        $signedparams = lti_sign_parameters($params, $endpoint, "POST", $consumerkey, $consumersecret);
        $params['oauth_signature'] = $signedparams['oauth_signature'];

        return $params;
    }
}
