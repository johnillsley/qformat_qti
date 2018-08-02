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
    /** @var SimpleXMLElement $imsmanifestresources */
    private $imsmanifestresources;
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

    /**
     * Builds the xml needed for a qti question using a template that contains all common components used for all question types
     * @param object $xmlparam containing parameters to be inserted into xml template
     * @return string xml content
     */
    public function exportpreprocess() {
        global $CFG, $USER, $COURSE;

        // QTI export should not contain category information even if it has been selected on the export form.
        $this->cattofile = false;

        // Create the zip file that will contain all export files.
        mkdir( $CFG->dataroot.'/temp/qti-export-'.$USER->sesskey );
        $this->zip = new ZipArchive();

        $filename = question_default_export_filename($COURSE, $this->category) . $this->export_file_extension();
        $this->zip->open( $CFG->dataroot.'/temp/'.$filename, ZIPARCHIVE::CREATE);

        // Create header for imsmanifest.xml.
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
     * Scans a question object (including answers) for media files - updates reference to them and adds files to QTI zip archive
     * @param object $question
     * @param integer $contextid context of question (question category)
     * @return object $question modified to reference the images folder
     */
    private function find_images( $question, $contextid ) {
        global $CFG, $USER;

        $id = $question->id;

        foreach ($question as $k => $v) {

            if (is_array($v)) {
                $v[$k] = $this->find_images( $v, $contextid);
            } else if (is_object( $v )) {
                $v->$k = $this->find_images( $v, $contextid);
            } else {
                if ($pos = stripos( $v, '@@PLUGINFILE@@')) {
                    // Found an image.
                    $sub = substr( $v, ($pos + 15) );
                    $filename = urldecode(substr( $sub, 0, strpos( $sub, '"')));
                    // Make sure images with the same name are dealt with.
                    $safefilename = $k . '-' . $id . '-' . str_replace(" ", "", $filename);

                    // Update URL.
                    $v = str_replace( '@@PLUGINFILE@@', 'images', $v );
                    $v = str_replace( $filename, $safefilename, $v );

                    // Get file and add to zip archive.
                    $fs = get_file_storage();
                    $file = $fs->get_file($contextid, 'question', $k, $id, '/', $filename );
                    $file->copy_content_to($CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/'.$safefilename);
                    $this->zip->addFile( $CFG->dataroot . '/temp/qti-export-'.$USER->sesskey.'/'. $safefilename,
                            'images/'.$safefilename);
                }
                $question->$k = $v;
            }
        }
        return $question;
    }

    /**
     * Creates an xml file for a question and adds it to the zip archive
     * @param object $question
     * @return true
     */
    public function writequestion($question) {
        global $CFG, $USER;

        $contextid = $question->contextid;

        // Scan whole $question object for string @@PLUGINFILE@@ which indicates a reference to an image file.
        $question = $this->find_images( $question, $contextid );

        $validquestion = false;
        $xmlparam = new stdClass();
        $xmlparam->question_id = $this->clean_sting($CFG->wwwroot).'_'.$question->id;
        $xmlparam->question_title = $question->name;
        $xmlparam->question_text = $this->cleanhtml($question->questiontext);
        $xmlparam->response_mapping = '';
        $xmlparam->question_correct_feedback = $this->cleanhtml($question->options->correctfeedback);
        $xmlparam->question_partially_correct_feedback = $this->cleanhtml($question->options->partiallycorrectfeedback);
        $xmlparam->question_incorrect_feedback = $this->cleanhtml($question->options->incorrectfeedback);
        $xmlparam->correct_response = '';
        $xmlparam->question_correct_answers = '';
        $xmlparam->question_wrong_answers = '';
        $xmlparam->response_declaration = '';
        $xmlparam->output_declaration = '';
        $xmlparam->response_fb_unasnswered = '';
        $xmlparam->response_fb_correct = '';
        $xmlparam->response_fb_partially = '';
        $xmlparam->question_max_score = 0;

        // Output depends on question type.
        switch($question->qtype) {

            case 'category':

                break;

            case 'description':

                break;

            case 'essay':

                break;

            case 'truefalse':

                $this->process_multichoice_question($xmlparam, $question);
                $validquestion = true;
                break;

            case 'multianswer':

                $xmlparam->questioninteraction = '';

                foreach ($question->options->questions as $qid => $subquestion) {

                    switch($subquestion->qtype) {
                        case 'numerical':
                            $this->process_numerical_question($xmlparam, $subquestion);
                            break;

                        case 'shortanswer':
                            $this->process_shortanswer_question($xmlparam, $subquestion);
                            break;

                        case 'multichoice':
                            $this->process_multichoice_question($xmlparam, $subquestion);
                            break;
                    }
                    // Put form element into question item body.
                    $xmlparam->question_text = str_replace(
                            '{#'.$qid.'}',
                            $xmlparam->questioninteraction,
                            $xmlparam->question_text);
                    // Now the form element is embedded no need to add it in conventional itemBody.
                    $xmlparam->questioninteraction = "";
                }

                $validquestion = true;
                break;

            case 'multichoice':

                $this->process_multichoice_question($xmlparam, $question);
                $validquestion = true;
                break;

            case 'shortanswer':

                $this->process_shortanswer_question($xmlparam, $question);
                $validquestion = true;
                break;

            case 'numerical':

                $this->process_numerical_question($xmlparam, $question);
                $validquestion = true;
                break;

            case 'match':

                break;
        }

        if ($validquestion) {

            $xmlquestion = qti_export_build_xml($xmlparam);
            $filename = $this->xml_question_filename($question);
            $this->imsmanifest_resource($question, $filename);

            $fp = fopen( $CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/'.$filename, "w" );
            fwrite($fp, $xmlquestion);
            fclose($fp);
            $this->zip->addFile( $CFG->dataroot.'/temp/qti-export-'.$USER->sesskey.'/'.$filename, $filename);
        }
        return true;
    }

    /**
     * Convert a question of type multichoice to QTI format (also deals with truefalse question type)
     * set all xml parameters to be inserted into xml template
     * @param object &$xmlparam
     * @param object $question
     * @return void
     */
    private function process_multichoice_question(&$xmlparam, $question) {

        // This function is also used to build truefalse type questions.
        $responseid = $this->get_response_id($question);
        $shuffleanswers = ($question->options->shuffleanswers == 1) ? "true" : "false";
        $xmlparam->response_type = "identifier";
        $responsemappings = "";
        $correctresponses = "";
        $correctanswers = "";
        $wronganswers = "";

        if ($question->options->single == 1 || $question->qtype == 'truefalse') {
            // Single answer multichoice.
            $responsecardinality = "single";
            $maxchoices = 1;
        } else {
            // Multi answer multichoice.
            $responsecardinality = "multiple";
            $maxchoices = 0;
        }
        $inspiratruefalse = ($question->qtype == 'truefalse') ? ' inspera:variant="true_false"' : '';
        $xmlparam->questioninteraction = '
    <choiceInteraction responseIdentifier="'.$responseid.'" shuffle="'.$shuffleanswers.'"
        maxChoices="'.$maxchoices.'"'.$inspiratruefalse.'>
        ';
        foreach ($question->options->answers as $answer) {
            $member = '
                                   <member>
                                     <baseValue baseType="identifier">CHOICE_'.$answer->id.'</baseValue>
                                     <variable identifier="'.$responseid.'"/>
                                   </member>';

            $xmlparam->questioninteraction .= '
      <simpleChoice identifier="CHOICE_'.$answer->id.'">'.$answer->answer.
                    '<feedbackInline outcomeIdentifier="FEEDBACK" identifier="CHOICE_'.$answer->id.'" showHide="show">'
                    .strip_tags($this->cleanhtml($answer->feedback)).'</feedbackInline>
      </simpleChoice>';

            if ($answer->fraction > 0) {
                $correctresponses .= '
      <value>CHOICE_'.$answer->id.'</value>';
                $responsemappings .= '
      <mapEntry mapKey="CHOICE_'.$answer->id.'" mappedValue="'.$answer->fraction * $question->defaultmark.'"/>';
                $xmlparam->question_max_score += $answer->fraction * $question->defaultmark;
                $correctanswers .= $member;
            } else {
                $wronganswers .= $member;
            }
        }
        $xmlparam->questioninteraction .= '
    </choiceInteraction>';
        $xmlparam->response_declaration .= '
  <responseDeclaration identifier="'.$responseid.'" cardinality="'.$responsecardinality.'" baseType="identifier">
    <correctResponse>
'.$correctresponses.'
    </correctResponse>
    <mapping defaultValue="0">
'.$responsemappings.'
    </mapping>
  </responseDeclaration>';

        $xmlparam->output_declaration .= '
  <outcomeDeclaration identifier="isCorrect_'.$responseid.'" cardinality="single" baseType="string"/>';

        // Create response condition for SCORE.
        $xmlparam->response_condition_score .= '
    <responseCondition>
      <responseIf>
        <and>
          <isNull>
            <variable identifier="'.$responseid.'"/>
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
            <mapResponse identifier="'.$responseid.'"/>
          </sum>
        </setOutcomeValue>
      </responseElse>
    </responseCondition>';

        // Create response conditions for FEEDBACK.
        $xmlparam->response_fb_unasnswered .= '
          <isNull>
            <variable identifier="'.$responseid.'"/>
          </isNull>';

        $xmlparam->response_fb_correct .= '
        <and>
'.$correctanswers.'
          <not>
            <or>
'.$wronganswers.'
            </or>
          </not>
        </and>';

        $xmlparam->response_fb_partially .= '
        <and>
'.$correctanswers.'
        </and>';
    }

    /**
     * Convert a question of type shortanswer - set all xml parameters to be inserted into xml template
     * @param object &$xmlparam
     * @param object $question
     * @return void
     */
    private function process_shortanswer_question(&$xmlparam, $question) {

        $responseid = $this->get_response_id($question);
        $casesensitive = ($question->options->usecase == 0) ? 'false' : 'true';

        $xmlparam->question_max_score++; // Make all numeric questions score one point.
        $xmlparam->questioninteraction = '
    <textEntryInteraction expectedLength="50" responseIdentifier="'.$responseid.'" inspera:inputFieldWidth="20"/>';

        $correctanswers = "";
        foreach ($question->options->answers as $answer) {
            $correctanswers .= '
          <stringMatch caseSensitive="'.$casesensitive.'" inspera:ignoredCharacters=" " xmlns:inspera="http://www.inspera.no/qti">
            <baseValue baseType="string">'.$answer->answer.'</baseValue>
            <variable identifier="'.$responseid.'"/>
          </stringMatch>';
        }
        $xmlparam->question_correct_answers .= $correctanswers;

        $xmlparam->response_declaration .= '
  <responseDeclaration identifier="'.$responseid.'" cardinality="single" baseType="string">
    <correctResponse>
      <value>'.array_shift($question->options->answers)->answer.'</value>
    </correctResponse>
  </responseDeclaration>';

        $xmlparam->output_declaration .= '
  <outcomeDeclaration identifier="isCorrect_'.$responseid.'" cardinality="single" baseType="string"/>';

        // Create response condition for SCORE.
        $xmlparam->response_condition_score .= '
    <responseCondition>
      <responseIf>
        <or>
'.$correctanswers.'
        </or>
        <setOutcomeValue identifier="SCORE">
          <sum>
            <variable identifier="SCORE"/>
            <variable identifier="SCORE_EACH_CORRECT"/>
          </sum>
        </setOutcomeValue>
        <setOutcomeValue identifier="isCorrect_'.$responseid.'">
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
			<variable identifier="'.$responseid.'"/>
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

        // Create response conditions for FEEDBACK.
        $xmlparam->response_fb_unasnswered .= '
          <isNull>
            <variable identifier="'.$responseid.'"/>
          </isNull>';

        $xmlparam->response_fb_correct .= '
        <or>'.$correctanswers. '
        </or>';

        $xmlparam->response_fb_partially .= '
        <or>'.$correctanswers. '
        </or>';
    }

    /**
     * Convert a question of type numerical - set all xml parameters to be inserted into xml template
     * @param object &$xmlparam
     * @param object $question
     * @return void
     */
    private function process_numerical_question(&$xmlparam, $question) {

        $responseid = $this->get_response_id($question);

        $xmlparam->question_max_score++; // Make all numeric questions score one point.
        $xmlparam->questioninteraction = '
    <textEntryInteraction expectedLength="0" responseIdentifier="'.$responseid.'"
        inspera:inputFieldWidth="3" inspera:type="numeric"/>';

        $answer = array_shift($question->options->answers); // Only take the first answer if there are more than one.

        $answer->tolerance = (is_numeric($answer->tolerance)) ? $answer->tolerance : 0;
        $lowervalue = $answer->answer - $answer->tolerance;
        $uppervalue = $answer->answer + $answer->tolerance;

        $xmlparam->response_declaration .= '
  <responseDeclaration identifier="'.$responseid.'" cardinality="single" baseType="float">
    <correctResponse>
      <value>'.$lowervalue.'</value>
    </correctResponse>
  </responseDeclaration>';

        $xmlparam->output_declaration .= '
  <outcomeDeclaration identifier="isCorrect_'.$responseid.'" cardinality="single" baseType="string"/>';

        $correctanswer = '
        <and>
          <gte>
            <variable identifier="'.$responseid.'"/>
            <baseValue baseType="float">'.$lowervalue.'</baseValue>
          </gte>
          <lte>
            <variable identifier="'.$responseid.'"/>
            <baseValue baseType="float">'.$uppervalue.'</baseValue>
          </lte>
        </and>';

        $xmlparam->question_correct_answers .= $correctanswer;

        // Create response condition for SCORE.
        $xmlparam->response_condition_score .= '
    <responseCondition>
      <responseIf>
'.$correctanswer.'
        <setOutcomeValue identifier="SCORE">
          <sum>
            <variable identifier="SCORE"/>
            <variable identifier="SCORE_EACH_CORRECT"/>
          </sum>
        </setOutcomeValue>
        <setOutcomeValue identifier="isCorrect_'.$responseid.'">
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
			<variable identifier="'.$responseid.'"/>
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

        // Create response conditions for FEEDBACK.
        $xmlparam->response_fb_unasnswered .= '
          <isNull>
            <variable identifier="'.$responseid.'"/>
          </isNull>';

        $xmlparam->response_fb_correct .= $correctanswer;

        $xmlparam->response_fb_partially .= $correctanswer;
    }

    /**
     * Makes a unique response id based on Moodle question id and site base url
     * @param object $question
     * @return string response id
     */
    private function get_response_id($question) {
        global $CFG;

        return "RESPONSE_".$this->clean_sting($CFG->wwwroot).'_'.$question->id;
    }


    /**
     * Adds a reference to a question in the imsmanifest.xml file
     * @param object $question
     * @param string $filename
     * @return void
     */
    private function imsmanifest_resource($question, $filename) {
        global $CFG;

        $resource = $this->imsmanifestresources->addChild("resource");

        $resource->addAttribute("identifier", $CFG->wwwroot."-".$question->id);
        $resource->addAttribute("type", "imsqti_item_xmlv2p1");
        $resource->addAttribute("href", $filename);

        $metadata = $resource->addChild("metadata");
        $imsmdlom = $metadata->addChild("imsmd:imsmd:lom");
        $imsmdgeneral = $imsmdlom->addChild("imsmd:imsmd:general");
        $imsmdtitle = $imsmdgeneral->addChild("imsmd:imsmd:title");
        $imsmdlangstring = $imsmdtitle->addChild("imsmd:imsmd:langstring", $question->name);
        $imsmdlangstring->addAttribute("xml:lang", "no");

        $file = $resource->addChild("file");
        $file->addAttribute("href", $filename);
        $file->addAttribute("href", $filename);
    }

    /**
     * Returns the filename for a QTI xml file
     * @param object $question
     * @return string filename
     */
    private function xml_question_filename($question) {

        return "content_question_qti2_".$question->qtype."_".$question->id.".xml";
    }

    /**
     * Returns the SimpleXMLElement object which will be added to for each question
     * When complete this forms the content of the imsmanifest.xml file
     * @return object SimpleXMLElement
     */
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

        $this->imsmanifestresources = $imsmanifest->addChild("resources");

        return $imsmanifest;
    }

    /**
     * Cleans HTML from Moodle content to ensure that it will work in Inspira
     * @param string $html
     * @return string $html filtered to remove problems
     */
    private function cleanhtml($html) {

        $dom = new DOMDocument('1.0', 'UTF-8'); // Init new DOMDocument.
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', "UTF-8");
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);            // Create a new XPath.
        $dom->normalizeDocument();
        $nodes = $xpath->query('//*');

        // Remove tag attributes that will not make sense in target system.
        foreach ($nodes as $node) {             // Iterate over found elements.
            $node->removeAttribute('class');    // Remove class attribute.
            $node->removeAttribute('style');    // Remove style attribute.
            $node->removeAttribute('role');     // Remove role attribute.
        }
        $html = $dom->saveHTML($dom->documentElement);

        // Fix all singelton tags so that they pass xhtml validation (add trailing forward slash).
        $singletontags = array('area', 'br', 'col', 'embed', 'hr', 'img', 'input', 'param', 'source', 'track', 'wbr' );
        foreach ($singletontags as $tag) {
            $html = preg_replace("/<$tag([^>]*)\>/i", "<$tag$1 />", $html);
        }
        return $html;
    }

    /**
     * Deletes all temp files and folder
     * @param string $src location of temp folder
     * @return void
     */
    private function rrmdir($src) {
        $dir = opendir($src);
        while (false !== ( $file = readdir($dir)) ) {
            if (( $file != '.' ) && ( $file != '..' )) {
                $full = $src . '/' . $file;
                if ( is_dir($full) ) {
                    rrmdir($full);
                } else {
                    unlink($full);
                }
            }
        }
        closedir($dir);
        rmdir($src);
    }

    /**
     * Removes all non alphanumeric characters so string can be used as unique id in QTI format
     * @param string $str
     * @return string $str
     */
    private function clean_sting($str) {

        return preg_replace("/[^a-zA-Z0-9]+/", "", $str);
    }

}