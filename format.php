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
 * QTI 2.1. format question exporter for use with the Inspira online exam system. Due to the bespoke implementation
 * of QTI in Inspira the exports from this plugin are unlikely to successfully import into other QTI systems.
 * The QTI export consists of a compressed zip file containing...
 *  - an XML definition file for each question
 *  - a folder containing all images used in questions and answers
 *  - an imsmanifest.xml file that is an index for all the questions
 * This plugin does not currently perform question imports.
 * The plugin can export the following Moodle question types
 *  - Multiple choice (single or multiple answer)
 *  - Short answer
 *  - Numerical
 *  The QTI format definition is here https://www.imsglobal.org/question/index.html#version2.1
 *
 * @package    qformat_qti
 * @author     John Illsley <j.s.illsley@bath.ac.uk>
 * @copyright  2018 University of Bath
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once('template.php');

class qformat_qti extends qformat_default {

    /** @var SimpleXMLElement $imsmanifest */
    private $imsmanifest;
    /** @var SimpleXMLElement $imsmanifest_resources */
    private $imsmanifest_resources;
    /** @var ZipArchive $zip */
    private $zip;

    /**
     * Returns false to indicate that this question format plugin does not support question importing
     * @return false
     */
    public function provide_import() {
        return false;
    }

    /**
     * Returns true to indicate that this question format plugin can export questions from Moodle
     * @return true
     */
    public function provide_export() {
        return true;
    }

    /**
     * Returns the file type for the question format
     * @return string .zip
     */
    public function export_file_extension() {
        return '.zip';
    }

    public function exportpreprocess() {
        global $CFG, $USER, $COURSE;

        // QTI export should not contain category information even if it has been selected on the export form.
        $this->cattofile = false;

        // Create the zip file that will contain all export files
        mkdir( $CFG->dataroot.'/temp/qti-export-'.$USER->sesskey );
        $this->zip = new ZipArchive();

        $filename = question_default_export_filename($COURSE, $this->category) . $this->export_file_extension();
        $this->zip->open( $CFG->dataroot.'/temp/'.$filename, ZIPARCHIVE::CREATE);

        // Create header for imsmanifest.xml
        $this->imsmanifest = $this->imsmanifest_header();

        return true;
    }

    protected function presave_process($content) {
        global $CFG, $USER, $COURSE;

        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($this->imsmanifest->asXML());

        $fp = fopen( $CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/imsmanifest.xml', "w" );
        fwrite($fp, $dom->saveXML());
        fclose($fp);
        $this->zip->addFile( $CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/imsmanifest.xml', 'imsmanifest.xml');
        $this->zip->close();

        $filename = question_default_export_filename($COURSE, $this->category) . $this->export_file_extension();
        $this->rrmdir($CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/');
        send_temp_file( $CFG->dataroot.'/temp/'.$filename, $filename );

        return $content;
    }

    /**
     * Scans a question object (including answers) for media files - updates reference to them and adds files to zip archive
     * @param object $question
     * @param integer $contextid
     * @return object $question
     */
    private function find_images( $question, $contextid ) {
        global $CFG, $USER;

        $id = $question->id;

        foreach( $question as $k=>$v ) {

            if (is_array($v)) {
                $v[$k] = $this->find_images( $v, $contextid);
            } elseif(is_object( $v )) {
                $v->$k = $this->find_images( $v, $contextid);
            } else {
                if( $pos = stripos( $v, '@@PLUGINFILE@@' ) ) {
                    // FOUND AN IMAGE
                    $sub =  substr( $v, ($pos + 15) );
                    $filename = urldecode(substr( $sub, 0, strpos( $sub, '"')));
                    // NEED TO MAKE SURE MORE THAN ONE IMAGE WITH THE SAME NAME IS DEALT WITH
                    $safefilename = $k . '-' . $id . '-' . str_replace(" ", "", $filename);

                    // UPDATE URL
                    $v = str_replace( '@@PLUGINFILE@@', 'images', $v );
                    $v = str_replace( $filename, $safefilename, $v );

                    // GET FILE AND ADD TO ZIP FILE
                    $fs = get_file_storage();
                    $file = $fs->get_file($contextid, 'question', $k, $id, '/', $filename );
                    $file->copy_content_to($CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/'.$safefilename);
                    $this->zip->addFile( $CFG->dataroot . '/temp/qti-export-'.$USER->sesskey.'/'. $safefilename, 'images/'.$safefilename);
                }
                $question->$k = $v;
            }
        }

        return $question;
    }

    /**
     * Creates an xml file for a question and adds it to the zip archive
     * @param object $question
     * @return boolean true
     */
    public function writequestion($question) {
        global $CFG, $USER;

        $contextid = $question->contextid;

        // SCAN WHOLE ARRAY FOR @@PLUGINFILE@@
        $question = $this->find_images( $question, $contextid );

        $valid_question = false;
        $xml_param = new stdClass();
        $xml_param->question_id = $this->clean_sting($CFG->wwwroot).'_'.$question->id;
        $xml_param->question_title = $question->name;
        $xml_param->question_text = $this->cleanHTML($question->questiontext);
        $xml_param->response_mapping = '';
        $xml_param->question_correct_feedback = $this->cleanHTML($question->options->correctfeedback);
        $xml_param->question_partially_correct_feedback = $this->cleanHTML($question->options->partiallycorrectfeedback);
        $xml_param->question_incorrect_feedback = $this->cleanHTML($question->options->incorrectfeedback);
        $xml_param->correct_response = '';
        $xml_param->question_correct_answers = '';
        $xml_param->question_wrong_answers = '';
        $xml_param->response_declaration = '';
        $xml_param->output_declaration = '';
        $xml_param->response_fb_unasnswered = '';
        $xml_param->response_fb_correct = '';
        $xml_param->response_fb_partially = '';
        $xml_param->question_max_score = 0;

        // Output depends on question type.
        switch($question->qtype) {

            case 'category':

                break;
// =====================================================================================================================
            case 'description':

                break;
// =====================================================================================================================
            case 'essay':

                break;
// =====================================================================================================================
            case 'truefalse':

                break;
// =====================================================================================================================
            case 'multianswer':

                $xml_param->question_interaction = '';

                foreach($question->options->questions as $qid=>$sub_question) {

                    switch($sub_question->qtype) {
                        case 'numerical':
                            $this->process_numerical_question($xml_param, $sub_question);
                            break;

                        case 'shortanswer':
                            $this->process_shortanswer_question($xml_param, $sub_question);
                            break;

                        case 'multichoice':
                            $this->process_multichoice_question($xml_param, $sub_question);
                            break;
                    }
                    // Put form element into question item body
                    $xml_param->question_text = str_replace( '{#'.$qid.'}', $xml_param->question_interaction, $xml_param->question_text );
                    $xml_param->question_interaction = ""; // Now the form element is embedded no need to add it in conventional itemBody
                }

                $valid_question = true;
                break;
// =====================================================================================================================

            case 'multichoice':

                $this->process_multichoice_question($xml_param, $question);
                $valid_question = true;
                break;
// =====================================================================================================================
            case 'shortanswer':

                $this->process_shortanswer_question($xml_param, $question);
                $valid_question = true;
                break;
// =====================================================================================================================
            case 'numerical':

                $this->process_numerical_question($xml_param, $question);
                $valid_question = true;
                break;
// =====================================================================================================================
            case 'match':

                break;
// =====================================================================================================================
        }

        if($valid_question) {

            $xml_question = qti_export_build_xml($xml_param);
            $filename = $this->xml_question_filename($question);
            $this->imsmanifest_resource($question, $filename);

            $fp = fopen( $CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/'.$filename, "w" );
            fwrite($fp, $xml_question);
            fclose($fp);
            $this->zip->addFile( $CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/'.$filename, $filename);
        }
        return true;
    }

    private function process_multichoice_question(&$xml_param, $question) {

        $response_id = $this->get_response_id($question);
        $shuffle_answers = ($question->options->shuffleanswers==1) ? "true" : "false";
        $xml_param->response_type = "identifier";
        $xml_param->question_max_score = 0;
        $response_mappings = "";
        $correct_responses = "";
        $correct_answers = "";
        $wrong_answers = "";

        if($question->options->single==1) {
            // Single answer multichoice
            $response_cardinality = "single";
            $max_choices = 1;
        } else {
            // Multi answer multichoice
            $response_cardinality = "multiple";
            $max_choices = 0;
        }

        $xml_param->question_interaction = '
    <choiceInteraction responseIdentifier="'.$response_id.'" shuffle="'.$shuffle_answers.'" maxChoices="'.$max_choices.'">     
        ';
        foreach($question->options->answers as $answer) {
            $member = '
                                   <member>
                                     <baseValue baseType="identifier">CHOICE_'.$answer->id.'</baseValue>
                                     <variable identifier="'.$response_id.'"/>
                                   </member>';
            $xml_param->question_interaction .= '
      <simpleChoice identifier="CHOICE_'.$answer->id.'">'.$this->cleanHTML($answer->answer).'<feedbackInline outcomeIdentifier="FEEDBACK" identifier="CHOICE_'.$answer->id.'" showHide="show">'.$answer->feedback.'</feedbackInline></simpleChoice>';

            if($answer->fraction>0) {
                $correct_responses .= '
      <value>CHOICE_'.$answer->id.'</value>';
                $response_mappings .= '
      <mapEntry mapKey="CHOICE_'.$answer->id.'" mappedValue="'.$answer->fraction*$question->defaultmark.'"/>';
                $xml_param->question_max_score += $answer->fraction*$question->defaultmark;
                $correct_answers .= $member;
            } else {
                $wrong_answers .= $member;
            }
        }
        $xml_param->question_interaction .= '
    </choiceInteraction>';
        $xml_param->response_declaration .= '
  <responseDeclaration identifier="'.$response_id.'" cardinality="'.$response_cardinality.'" baseType="identifier">
    <correctResponse>
'.$correct_responses.'
    </correctResponse>
    <mapping defaultValue="0">
'.$response_mappings.'
    </mapping>
  </responseDeclaration>';

        $xml_param->output_declaration .= '
  <outcomeDeclaration identifier="isCorrect_'.$response_id.'" cardinality="single" baseType="string"/>';

        // Create response condition for SCORE
        $xml_param->response_condition_score = '
    <responseCondition>
      <responseIf>
        <and>
          <isNull>
            <variable identifier="'.$response_id.'"/>
          </isNull>
        </and>
        <setOutcomeValue identifier="SCORE">
		  <sum>
		    <variable identifier="SCORE"/>
			<variable identifier="SCORE_UNANSWERED"/>
		  </sum>
        </setOutcomeValue>
      </responseIf>
	  
      <responseElse>
        <setOutcomeValue identifier="SCORE">
          <sum>
            <variable identifier="SCORE"/>
            <mapResponse identifier="'.$response_id.'"/>
          </sum>
        </setOutcomeValue>
      </responseElse>
    </responseCondition>';

        $xml_param->response_fb_unasnswered .= '
          <isNull>
            <variable identifier="'.$response_id.'"/>
          </isNull>';

        $xml_param->response_fb_correct .= '
        <and>
'.$correct_answers.'
          <not>
            <or>
'.$wrong_answers.'
            </or>
          </not>
        </and>';

        $xml_param->response_fb_partially .= '
        <and>
'.$correct_answers.'
        </and>';
    }

    private function process_shortanswer_question(&$xml_param, $question) {

        $response_id = $this->get_response_id($question);
        $case_sensitive = ($question->options->usecase==0) ? 'false' : 'true';

        $xml_param->question_max_score++; // Make all numeric questions score one point
        $xml_param->question_interaction = '
    <textEntryInteraction expectedLength="50" responseIdentifier="'.$response_id.'" inspera:inputFieldWidth="20"/>';

        $correct_answers = "";
        foreach($question->options->answers as $answer) {
            $correct_answers .= '
          <stringMatch caseSensitive="'.$case_sensitive.'" inspera:ignoredCharacters=" " xmlns:inspera="http://www.inspera.no/qti">
            <baseValue baseType="string">'.$answer->answer.'</baseValue>
            <variable identifier="'.$response_id.'"/>
          </stringMatch>';
        }
        $xml_param->question_correct_answers .= $correct_answers;

        $xml_param->response_declaration .= '
  <responseDeclaration identifier="'.$response_id.'" cardinality="single" baseType="string">
    <correctResponse>
      <value>'.array_shift($question->options->answers)->answer.'</value>
    </correctResponse>
  </responseDeclaration>';

        $xml_param->output_declaration .= '
  <outcomeDeclaration identifier="isCorrect_'.$response_id.'" cardinality="single" baseType="string"/>';

        // Create response condition for SCORE
        $xml_param->response_condition_score .= '
    <responseCondition>
      <responseIf>
        <or>
'.$correct_answers.'
        </or>
        <setOutcomeValue identifier="SCORE">
          <sum>
            <variable identifier="SCORE"/>
            <variable identifier="SCORE_EACH_CORRECT"/>
          </sum>
        </setOutcomeValue>
        <setOutcomeValue identifier="isCorrect_'.$response_id.'">
          <baseValue baseType="string">true</baseValue>
        </setOutcomeValue>
      </responseIf>
      <responseElse>
        <setOutcomeValue identifier="SCORE">
		  <sum>
		    <variable identifier="SCORE"/>
			<variable identifier="SCORE_EACH_WRONG"/>
		  </sum>
		</setOutcomeValue>
	  </responseElse>
	</responseCondition>
                        
    <responseCondition>
	  <responseIf>
		<and>		
		  <isNull>
			<variable identifier="'.$response_id.'"/>
		  </isNull>
        </and>
		<setOutcomeValue identifier="SCORE">
		  <sum>
		    <variable identifier="SCORE"/>
			<variable identifier="SCORE_UNANSWERED"/>
		  </sum>
		</setOutcomeValue>
	  </responseIf>
	  <responseElse>
		<setOutcomeValue identifier="SCORE">
		  <sum>
			<variable identifier="SCORE"/>
		  </sum>
		</setOutcomeValue>
	  </responseElse>
	</responseCondition>';

        $xml_param->response_fb_unasnswered .= '
          <isNull>
            <variable identifier="'.$response_id.'"/>
          </isNull>';

        $xml_param->response_fb_correct .= '
        <or>'.$correct_answers. '
        </or>';

        $xml_param->response_fb_partially .= '
        <or>'.$correct_answers. '
        </or>';
    }

    private function process_numerical_question(&$xml_param, $question) {

        $response_id = $this->get_response_id($question);

        $xml_param->question_max_score++; // Make all numeric questions score one point
        $xml_param->question_interaction = '
    <textEntryInteraction expectedLength="0" responseIdentifier="'.$response_id.'" inspera:inputFieldWidth="3" inspera:type="numeric"/>';

        $answer = array_shift($question->options->answers); // Only take the first answer if there are more than one

        $answer->tolerance = (is_numeric($answer->tolerance)) ? $answer->tolerance : 0;
        $lower_value = $answer->answer - $answer->tolerance;
        $upper_value = $answer->answer + $answer->tolerance;

        $xml_param->response_declaration .= '
  <responseDeclaration identifier="'.$response_id.'" cardinality="single" baseType="float">
    <correctResponse>
      <value>'.$lower_value.'</value>
    </correctResponse>
  </responseDeclaration>';

        $xml_param->output_declaration .= '
  <outcomeDeclaration identifier="isCorrect_'.$response_id.'" cardinality="single" baseType="string"/>';

        $correct_answer = '        
        <and>
          <gte>
            <variable identifier="'.$response_id.'"/>
            <baseValue baseType="float">'.$lower_value.'</baseValue>
          </gte>
          <lte>
            <variable identifier="'.$response_id.'"/>
            <baseValue baseType="float">'.$upper_value.'</baseValue>
          </lte>
        </and>';

        $xml_param->question_correct_answers .= $correct_answer;

        // Create response condition for SCORE
        $xml_param->response_condition_score .= '
    <responseCondition>
      <responseIf>
'.$correct_answer.'
        <setOutcomeValue identifier="SCORE">
          <sum>
            <variable identifier="SCORE"/>
            <variable identifier="SCORE_EACH_CORRECT"/>
          </sum>
        </setOutcomeValue>
        <setOutcomeValue identifier="isCorrect_'.$response_id.'">
          <baseValue baseType="string">true</baseValue>
        </setOutcomeValue>
      </responseIf>
	  
      <responseElse>
        <setOutcomeValue identifier="SCORE">
          <sum>
            <variable identifier="SCORE"/>
            <variable identifier="SCORE_EACH_WRONG"/>
          </sum>
        </setOutcomeValue>
      </responseElse>
    </responseCondition>
    
    <responseCondition>
	  <responseIf>
		<and>		
		  <isNull>
			<variable identifier="'.$response_id.'"/>
		  </isNull>
        </and>
		<setOutcomeValue identifier="SCORE">
		  <sum>
		    <variable identifier="SCORE"/>
			<variable identifier="SCORE_UNANSWERED"/>
		  </sum>
		</setOutcomeValue>
	  </responseIf>
	  <responseElse>
		<setOutcomeValue identifier="SCORE">
		  <sum>
			<variable identifier="SCORE"/>
		  </sum>
		</setOutcomeValue>
	  </responseElse>
	</responseCondition>';

        $xml_param->response_fb_unasnswered .= '
          <isNull>
            <variable identifier="'.$response_id.'"/>
          </isNull>';

        $xml_param->response_fb_correct .= $correct_answer;

        $xml_param->response_fb_partially .= $correct_answer;
    }

    private function get_response_id($question) {
        global $CFG;

        return "RESPONSE_".$this->clean_sting($CFG->wwwroot).'_'.$question->id;
    }


    /**
     * Adds a reference to a question in the imsmanifest.xml file
     * @param object $question
     * @param string $filename
     * @return null
     */
    private function imsmanifest_resource($question, $filename) {
        global $CFG;

        $resource = $this->imsmanifest_resources->addChild("resource");

        $resource->addAttribute("identifier", $CFG->wwwroot."-".$question->id);
        $resource->addAttribute("type", "imsqti_item_xmlv2p1");
        $resource->addAttribute("href", $filename);

        $metadata = $resource->addChild("metadata");
        $imsmdlom = $metadata->addChild("imsmd:imsmd:lom");
        $imsmdgeneral = $imsmdlom->addChild("imsmd:imsmd:general");
        $imsmdtitle = $imsmdgeneral->addChild("imsmd:imsmd:title");
        $imsmdlangstring = $imsmdtitle->addChild("imsmd:imsmd:langstring", $question->name);
        $imsmdlangstring->addAttribute("xml:lang","no");

        $file = $resource->addChild("file");
        $file->addAttribute("href", $filename);
        $file->addAttribute("href", $filename);
    }

    /**
     * Returns the filename for a question xml file
     * @param object $question
     * @return string
     */
    private function xml_question_filename($question) {

        return "content_question_qti2_".$question->qtype."_".$question->id.".xml";
    }

    private function imsmanifest_header() {

        $imsmanifest = new SimpleXMLElement( "<manifest></manifest>");
        $imsmanifest->addAttribute("identifier", "MANIFEST");
        $imsmanifest->addAttribute("version", "1.1");
        $imsmanifest->addAttribute("xmlns", "http://www.imsglobal.org/xsd/imscp_v1p1");
        $imsmanifest->addAttribute("xmlns:xmlns:java", "http://xml.apache.org/xalan/java");
        $imsmanifest->addAttribute("xmlns:xmlns:imsmd", "http://www.imsglobal.org/xsd/imsmd_v1p2");

        $metadata = $imsmanifest->addChild("metadata");
        $imsmdlom = $metadata->addChild("imsmd:imsmd:lom");
        $imsmdlifecycle = $imsmdlom->addChild("imsmd:imsmd:lifecycle");
        $imsmdstatus = $imsmdlifecycle->addChild("imsmd:imsmd:status");
        $imsmdsource = $imsmdstatus->addChild("imsmd:imsmd:source");
        $imsmdlangstring1 = $imsmdsource->addChild("imsmd:imsmd:langstring", "LOMv1.0");
        $imsmdlangstring1->addAttribute("xml:xml:lang", "x-none");
        $imsmdvalue = $imsmdstatus->addChild("imsmd:imsmd:value");
        $imsmdlangstring2 = $imsmdvalue->addChild("imsmd:imsmd:langstring", "Draft");
        $imsmdlangstring2->addAttribute("xml:xml:lang", "x-none");

        $this->imsmanifest_resources = $imsmanifest->addChild("resources");

        return $imsmanifest;
    }

    private function cleanHTML($html) {

        $dom = new DOMDocument('1.0', 'UTF-8');                 // init new DOMDocument
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', "UTF-8");
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);            // create a new XPath
        $dom->normalizeDocument();
        $nodes = $xpath->query('//*');

        // remove tag attributes that will not make sense in target system
        foreach ($nodes as $node) {             // Iterate over found elements
            $node->removeAttribute('class');    // Remove class attribute
            $node->removeAttribute('style');    // Remove style attribute
            $node->removeAttribute('role');     // Remove role attribute
        }
        $html = $dom->saveHTML($dom->documentElement);

        // "Fix" all singelton tags so that they pass xhtml validation (add trailing forward slash)
        $singleton_tags = array('area', 'br', 'col', 'embed', 'hr', 'img', 'input', 'param', 'source', 'track', 'wbr' );
        foreach($singleton_tags as $tag) {
            $html = preg_replace("/<$tag([^>]*)\>/i", "<$tag$1 />", $html);
        }
        return $html;
    }

    private function rrmdir($src) {
        $dir = opendir($src);
        while(false !== ( $file = readdir($dir)) ) {
            if (( $file != '.' ) && ( $file != '..' )) {
                $full = $src . '/' . $file;
                if ( is_dir($full) ) {
                    rrmdir($full);
                }
                else {
                    unlink($full);
                }
            }
        }
        closedir($dir);
        rmdir($src);
    }

    private function clean_sting($str) {

        return preg_replace("/[^a-zA-Z0-9]+/", "", $str);
    }

}