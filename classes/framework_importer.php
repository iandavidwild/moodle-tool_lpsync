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
 * This file contains the form add/update a competency framework.
 *
 * @package   tool_lpsync
 * @copyright 2017 Ian Wild
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_lpsync;

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

use core_competency\api;
use grade_scale;
use stdClass;
use context_system;
use csv_import_reader;

/**
 * Import Competency framework form.
 *
 * @package   tool_lpsync
 * @copyright 2017 Ian Wild
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class framework_importer {

    /** @var string $error The errors message from reading the xml */
    var $error = '';

    /** @var array $flat The flat competencies tree */
    var $flat = array();
    /** @var array $framework The framework info */
    var $framework = array();
    var $mappings = array();
    var $importid = 0;
    var $importer = null;
    var $foundheaders = array();

    /** @var config */
    var $config = array();
    
    /**
     * Returns true if the database is configured, else returns false
     * 
     * @return bool
     */
    function is_configured() {
        $result = true;
        
        if(count($this->config) == 0) {
            $result = false;
        }
     
        return $result;
    }
    
    /**
     * Loads up settings and determines if anything has been configured or not. If there is no configuration data at all then
     * it populates its settings table with its defaults.
     * 
     * @return bool
     */
    function init() {
        global $DB;
        
        // Get details of external database from our config. Currently we do this one record at a time, which is a little clunky:
        $records = $DB->get_records('tool_lpsync', null, null);
    
        // if there aren't any entries in the table then we need to prepare them:
        if(count($records) == 0) {
            
            $rows = array(  'type' => 'mysqli', 
                            'host' => 'localhost', 
                            'user' => '',
                            'pass' => '',
                            'name' => '',
                            'table' => '',
                            
                            'parentidnumber' => 'parentidnumber',
                            'idnumber' => 'idnumber',
                            'shortname' => 'shortname', 
                            'description' => 'description',
                            'descriptionformat' => 'descriptionformat',
                            'scalevalues' => 'scalevalues',
                            'scaleconfiguration' => 'scaleconfiguration',
                    
                            'ruletype' => '',
                            'ruleoutcome' => '',
                            'ruleconfig' => '',
                    
                            'relatedidnumbers' => 'relatedidnumbers',
                            'isframework' => 'isframework',
                            'taxonomy' => 'taxonomy'
                    );
            
            foreach($rows as $name => $value) {
                 $object = new stdClass();
                 $object->name = $name;
                 $object->value = $value;
                 $DB->insert_record('tool_lpsync', $object);
            }
            
            // try that again
            $records = $DB->get_records('tool_lpsync');    
        }
        
        foreach($records as $record) {
            $this->config[$record->name] = $record->value;
        }
        
        return true;
    }
    
    /**
     * Connect to external database.
     *
     * @return ADOConnection
     * @throws moodle_exception
     */
    function db_connect() {
        global $CFG;
        
        if ($this->is_configured() === false) {
            throw new moodle_exception('dbcantconnect', 'tool_lpsync');
        }
        
        // Connect to the external database (forcing new connection).
        $authdb = ADONewConnection($this->db_type);
        if (!empty($CFG->debuglpsync)) {
            $authdb->debug = true;
            ob_start(); //Start output buffer to allow later use of the page headers.
        }
        
        $authdb->Connect($this->db_host, $this->db_user, $this->db_password, $this->db_name, true);
        $authdb->SetFetchMode(ADODB_FETCH_ASSOC);
        
        return $authdb;
    }
    
    /**
     * Returns attribute mappings between moodle capabilities and those listed in external db.
     *
     * @return array
     */
    function db_attributes() {
        $moodleattributes = array();
        // If we have custom fields then merge them with user fields.
        $customfields = $this->get_custom_user_profile_fields();
        if (!empty($customfields) && !empty($this->userfields)) {
            $userfields = array_merge($this->userfields, $customfields);
        } else {
            $userfields = $this->userfields;
        }
        
        foreach ($userfields as $field) {
            if (!empty($this->config->{"field_map_$field"})) {
                $moodleattributes[$field] = $this->config->{"field_map_$field"};
            }
        }
        $moodleattributes['username'] = $this->config->fielduser;
        return $moodleattributes;
    }
    
    public function fail($msg) {
        $this->error = $msg;
        return false;
    }

    public function get_importid() {
        return $this->importid;
    }

    public static function list_required_headers() {
        return array(
            get_string('parentidnumber', 'tool_lpsync'),
            get_string('idnumber', 'tool_lpsync'),
            get_string('shortname', 'tool_lpsync'),
            get_string('description', 'tool_lpsync'),
            get_string('descriptionformat', 'tool_lpsync'),
            get_string('scalevalues', 'tool_lpsync'),
            get_string('scaleconfiguration', 'tool_lpsync'),
            get_string('ruletype', 'tool_lpsync'),
            get_string('ruleoutcome', 'tool_lpsync'),
            get_string('ruleconfig', 'tool_lpsync'),
            get_string('relatedidnumbers', 'tool_lpsync'),
            get_string('isframework', 'tool_lpsync'),
            get_string('taxonomy', 'tool_lpsync'),
        );
    }

    public function list_found_headers() {
        return $this->foundheaders;
    }

    private function read_mapping_data($data) {
        if ($data) {
            return array(
                'parentidnumber' => $data->header0,   
                'idnumber' => $data->header1,
                'shortname' => $data->header2,
                'description' => $data->header3,
                'descriptionformat' => $data->header4,
                'scalevalues' => $data->header5,
                'scaleconfiguration' => $data->header6,
                'ruletype' => $data->header7,
                'ruleoutcome' => $data->header8,
                'ruleconfig' => $data->header9,
                'relatedidnumbers' => $data->header10,
                'exportid' => $data->header11,
                'isframework' => $data->header12,
                'taxonomies' => $data->header13
            );
        } else {
            return array(
                'parentidnumber' => 0,   
                'idnumber' => 1,
                'shortname' => 2,
                'description' => 3,
                'descriptionformat' => 4,
                'scalevalues' => 5,
                'scaleconfiguration' => 6,
                'ruletype' => 7,
                'ruleoutcome' => 8,
                'ruleconfig' => 9,
                'relatedidnumbers' => 10,
                'exportid' => 11,
                'isframework' => 12,
                'taxonomies' => 13
            );
        }
    }

    private function get_row_data($row, $index) {
        if ($index < 0) {
            return '';
        }
        return isset($row[$index]) ? $row[$index] : '';
    }

    /**
     * Constructor - initialises the DB connection.
     */
    public function parse($text = null, $encoding = null, $delimiter = null, $importid = 0, $mappingdata = null) {
        global $CFG;
        
         // The format of our records is:
         // Parent ID number, ID number, Shortname, Description, Description format, Scale values, Scale configuration, Rule type, Rule outcome, Rule config, Is framework, Taxonomy
         
         // The idnumber is concatenated with the category names.
         require_once($CFG->libdir . '/csvlib.class.php');
         
         $type = 'competency_framework';
         
         if (!$importid) {
         if ($text === null) {
         return;
         }
         $this->importid = csv_import_reader::get_new_iid($type);
         
         $this->importer = new csv_import_reader($this->importid, $type);
         
         if (!$this->importer->load_csv_content($text, $encoding, $delimiter)) {
         $this->fail(get_string('invalidimportfile', 'tool_lpsync'));
         $this->importer->cleanup();
         return;
         }
         
         } else {
         $this->importid = $importid;
         
         $this->importer = new csv_import_reader($this->importid, $type);
         }
         
         
         if (!$this->importer->init()) {
         $this->fail(get_string('invalidimportfile', 'tool_lpsync'));
         $this->importer->cleanup();
         return;
         }
         
         $this->foundheaders = $this->importer->get_columns();
         
         $domainid = 1;
         
         $flat = array();
         $framework = null;
         
         while ($row = $this->importer->next()) {
         $mapping = $this->read_mapping_data($mappingdata);
         
         $parentidnumber = $this->get_row_data($row, $mapping['parentidnumber']);
         $idnumber = $this->get_row_data($row, $mapping['idnumber']);
         $shortname = $this->get_row_data($row, $mapping['shortname']);
         $description = $this->get_row_data($row, $mapping['description']);
         $descriptionformat = $this->get_row_data($row, $mapping['descriptionformat']);
         $scalevalues = $this->get_row_data($row, $mapping['scalevalues']);
         $scaleconfiguration = $this->get_row_data($row, $mapping['scaleconfiguration']);
         $ruletype = $this->get_row_data($row, $mapping['ruletype']);
         $ruleoutcome = $this->get_row_data($row, $mapping['ruleoutcome']);
         $ruleconfig = $this->get_row_data($row, $mapping['ruleconfig']);
         $relatedidnumbers = $this->get_row_data($row, $mapping['relatedidnumbers']);
         $exportid = $this->get_row_data($row, $mapping['exportid']);
         $isframework = $this->get_row_data($row, $mapping['isframework']);
         $taxonomies = $this->get_row_data($row, $mapping['taxonomies']);
         
         if ($isframework) {
         $framework = new stdClass();
         $framework->idnumber = shorten_text(clean_param($idnumber, PARAM_TEXT), 100);
         $framework->shortname = shorten_text(clean_param($shortname, PARAM_TEXT), 100);
         $framework->description = clean_param($description, PARAM_RAW);
         $framework->descriptionformat = clean_param($descriptionformat, PARAM_INT);
         $framework->scalevalues = $scalevalues;
         $framework->scaleconfiguration = $scaleconfiguration;
         $framework->taxonomies = $taxonomies;
         $framework->children = array();
         } else {
         $competency = new stdClass();
         $competency->parentidnumber = clean_param($parentidnumber, PARAM_TEXT);
         $competency->idnumber = shorten_text(clean_param($idnumber, PARAM_TEXT), 100);
         $competency->shortname = shorten_text(clean_param($shortname, PARAM_TEXT), 100);
         $competency->description = clean_param($description, PARAM_RAW);
         $competency->descriptionformat = clean_param($descriptionformat, PARAM_INT);
         $competency->ruletype = $ruletype;
         $competency->ruleoutcome = clean_param($ruleoutcome, PARAM_INT);
         $competency->ruleconfig = $ruleconfig;
         $competency->relatedidnumbers = $relatedidnumbers;
         $competency->exportid = $exportid;
         $competency->scalevalues = $scalevalues;
         $competency->scaleconfiguration = $scaleconfiguration;
         $competency->children = array();
         $flat[$idnumber] = $competency;
         }
         }
         $this->flat = $flat;
         $this->framework = $framework;
         
         $this->importer->close();
         if ($this->framework == null) {
         $this->fail(get_string('invalidimportfile', 'tool_lpsync'));
         return;
         } else {
         // Build a tree from this flat list.
         $this->add_children($this->framework, '');
         }
    }
    
    /**
     * Constructor - initialise this instance.
     */
    public function __construct() {
        $this->init();        
    }

    /**
     * Recursive function to build a tree from the flat list of nodes.
     */
    public function add_children(& $node, $parentidnumber) {
        foreach ($this->flat as $competency) {
            if ($competency->parentidnumber == $parentidnumber) {
                $node->children[] = $competency;
                $this->add_children($competency, $competency->idnumber);
            } 
        }
    }

    /**
     * @return array of errors from parsing the xml.
     */
    public function get_error() {
        return $this->error;
    }

    /**
     * Recursive function to add a competency with all it's children.
     */
    public function create_competency($record, $parent, $framework) {
        $competency = new stdClass();
        $competency->competencyframeworkid = $framework->get_id();
        $competency->shortname = $record->shortname;
        if (!empty($record->description)) {
            $competency->description = $record->description;
            $competency->descriptionformat = $record->descriptionformat;
        }
        if ($record->scalevalues) {
            $competency->scaleid = $this->get_scale_id($record->scalevalues, $competency->shortname);
            $competency->scaleconfiguration = $this->get_scale_configuration($competency->scaleid, $record->scaleconfiguration);
        }
        if ($parent) {
            $competency->parentid = $parent->get_id();
        } else {
            $competency->parentid = 0;
        }
        $competency->idnumber = $record->idnumber;

        if (!empty($competency->idnumber) && !empty($competency->shortname)) {
            $comp = api::create_competency($competency);
            if ($record->exportid) {
                $this->mappings[$record->exportid] = $comp;
            }
            $record->createdcomp = $comp;
            foreach ($record->children as $child) {
                $this->create_competency($child, $comp, $framework);
            }

            return $comp;
        }
        return false;
    }

    /**
     * Recreate the scale config to point to a new scaleid.
     */
    public function get_scale_configuration($scaleid, $config) {
        $asarray = json_decode($config);
        $asarray[0]->scaleid = $scaleid;
        return json_encode($asarray);
    }

    /**
     * Search for a global scale that matches this set of scalevalues.
     * If one is not found it will be created.
     */
    public function get_scale_id($scalevalues, $competencyname) {
        global $CFG, $USER;

        require_once($CFG->libdir . '/gradelib.php');

        $allscales = grade_scale::fetch_all_global();
        $matchingscale = false;
        foreach ($allscales as $scale) {
            if ($scale->compact_items() == $scalevalues) {
                $matchingscale = $scale;
            }
        }
        if (!$matchingscale) {
            // Create it.
            $newscale = new grade_scale();
            $newscale->name = get_string('competencyscale', 'tool_lpsync', $competencyname);
            $newscale->courseid = 0;
            $newscale->userid = $USER->id;
            $newscale->scale = $scalevalues;
            $newscale->description = get_string('competencyscaledescription', 'tool_lpsync');
            $newscale->insert();
            return $newscale->id;
        }
        return $matchingscale->id;
    }

    private function set_related($record) {
        $comp = $record->createdcomp;
        if ($record->relatedidnumbers) {
            $allidnumbers = explode(',', $record->relatedidnumbers);
            foreach ($allidnumbers as $rawidnumber) {
                $idnumber = str_replace('%2C', ',', $rawidnumber);

                if (isset($this->flat[$idnumber])) {
                    $relatedcomp = $this->flat[$idnumber]->createdcomp;
                    api::add_related_competency($comp->get_id(), $relatedcomp->get_id());
                }
            }
        }
        foreach ($record->children as $child) {
            $this->set_related($child);
        }
    }

    private function set_rules($record) {
        $comp = $record->createdcomp;
        if ($record->ruletype) {
            $class = $record->ruletype;
            if (class_exists($class)) {
                $oldruleconfig = $record->ruleconfig;
                if ($oldruleconfig == "null") {
                    $oldruleconfig = null;
                }
                $newruleconfig = $class::migrate_config($oldruleconfig, $this->mappings);
                $comp->set_ruleconfig($newruleconfig);
                $comp->set_ruletype($class);
                $comp->set_ruleoutcome($record->ruleoutcome);
                $comp->update();
            }
        }
        foreach ($record->children as $child) {
            $this->set_rules($child);
        }
    }

    /**
     * Do the job.
     */
    public function import() {
        $record = clone $this->framework;
        unset($record->children);

        $record->scaleid = $this->get_scale_id($record->scalevalues, $record->shortname);
        $record->scaleconfiguration = $this->get_scale_configuration($record->scaleid, $record->scaleconfiguration);
        unset($record->scalevalues);
        $record->contextid = context_system::instance()->id;
        
        $framework = api::create_framework($record);

        // Now all the children;
        foreach ($this->framework->children as $comp) {
            $this->create_competency($comp, null, $framework);
        }

        // Now create the rules.
        foreach ($this->framework->children as $record) {
            $this->set_rules($record);
            $this->set_related($record);
        }

        $this->importer->cleanup();
        return $framework;
    }
}
