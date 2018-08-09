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
 * Unit tests for the Moodle qti format.
 *
 * @package    qformat_qti
 * @author     John Illsley <j.s.illsley@bath.ac.uk>
 * @copyright  2018 University of Bath
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/qti/format.php');
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');

/**
 * Unit tests for the Moodle qti format.
 *
 * @package    qformat_qti
 * @author     John Illsley <j.s.illsley@bath.ac.uk>
 * @copyright  2018 University of Bath
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bath
 */
class qformat_qti_test extends question_testcase {

    private function assert_same_qti($expectedtext, $text) {

        $parser = xml_parser_create();
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, $expectedtext, $expectedvalues, $expectedtags);
        xml_parser_free($parser);

        $parser = xml_parser_create();
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, $text, $actualvalues, $actualtags);
        xml_parser_free($parser);

        $this->assertEquals($expectedvalues, $actualvalues);
        $this->assertEquals($expectedtags, $actualtags);
    }

    private function get_actual_output(&$qdata) {
        global $CFG, $USER;

        $qdata->contextid = 1; // This is normally set in questionlib.php for any question export plugin.
        // It needs a value to run tests.

        $user = $this->getDataGenerator()->create_user(array('email' => 'user1@example.com', 'username' => 'user1'));

        $category = new stdClass();
        $category->name = "Qcat";

        $exporter = new qformat_qti();
        $exporter->category = $category;

        $this->assertTrue($exporter->exportpreprocess());

        $exporter->writequestion($qdata);
        $filename = "content_question_qti2_" . $qdata->qtype . "_" . $qdata->id . ".xml";
        $fp = fopen($CFG->dataroot . '/temp/qti-export-' . $USER->sesskey . '/' . $filename, "r");
        $output = fread($fp, filesize($CFG->dataroot . '/temp/qti-export-' . $USER->sesskey . '/' . $filename));

        return $output;
    }

    private function get_expected_output($qdata) {
        global $CFG;

        $fp2 = fopen($CFG->dirroot . '/question/format/qti/tests/fixtures/output_'.$qdata->qtype.'.xml', 'r');
        $expectedout = fread($fp2, filesize($CFG->dirroot . '/question/format/qti/tests/fixtures/output_'.$qdata->qtype.'.xml'));

        return $expectedout;
    }

    public function test_export_multichoice() {

        $this->resetAfterTest(true);

        $qdata = (object) array(
                'id' => 666,
                'parent' => 0,
                'name' => 'Q8',
                'questiontext' => "What's between orange and green in the spectrum?",
                'questiontextformat' => FORMAT_MOODLE,
                'generalfeedback' => '',
                'generalfeedbackformat' => FORMAT_MOODLE,
                'defaultmark' => 1,
                'penalty' => 0.3333333,
                'length' => 1,
                'qtype' => 'multichoice',
                'options' => (object) array(
                        'single' => 1,
                        'shuffleanswers' => '1',
                        'answernumbering' => 'abc',
                        'correctfeedback' => '',
                        'correctfeedbackformat' => FORMAT_MOODLE,
                        'partiallycorrectfeedback' => '',
                        'partiallycorrectfeedbackformat' => FORMAT_MOODLE,
                        'incorrectfeedback' => '',
                        'incorrectfeedbackformat' => FORMAT_MOODLE,
                        'answers' => array(
                                123 => (object) array(
                                        'id' => 123,
                                        'answer' => 'yellow',
                                        'answerformat' => FORMAT_MOODLE,
                                        'fraction' => 1,
                                        'feedback' => 'right; good!',
                                        'feedbackformat' => FORMAT_MOODLE,
                                ),
                                124 => (object) array(
                                        'id' => 124,
                                        'answer' => 'red',
                                        'answerformat' => FORMAT_MOODLE,
                                        'fraction' => 0,
                                        'feedback' => "wrong, it's yellow",
                                        'feedbackformat' => FORMAT_HTML,
                                ),
                                125 => (object) array(
                                        'id' => 125,
                                        'answer' => 'blue',
                                        'answerformat' => FORMAT_PLAIN,
                                        'fraction' => 0,
                                        'feedback' => "wrong, it's yellow",
                                        'feedbackformat' => FORMAT_MOODLE,
                                ),
                        ),
                ),
        );

        $output = $this->get_actual_output($qdata);
        $expectedout = $this->get_expected_output($qdata);

        $this->assert_same_qti($expectedout, $output);
    }

    public function test_export_numerical() {

        $this->resetAfterTest(true);

        $qdata = (object) array(
                'id' => 666,
                'parent' => 0,
                'name' => 'Q5',
                'questiontext' => "What is a number from 1 to 5?",
                'questiontextformat' => FORMAT_MOODLE,
                'generalfeedback' => '',
                'generalfeedbackformat' => FORMAT_MOODLE,
                'defaultmark' => 1,
                'penalty' => 1,
                'length' => 1,
                'qtype' => 'numerical',
                'options' => (object) array(
                        'id' => 123,
                        'question' => 666,
                        'showunits' => 0,
                        'unitsleft' => 0,
                        'showunits' => 2,
                        'unitgradingtype' => 0,
                        'unitpenalty' => 0,
                        'correctfeedback' => 'Correct',
                        'correctfeedbackformat' => FORMAT_MOODLE,
                        'partiallycorrectfeedback' => 'Partially correct',
                        'partiallycorrectfeedbackformat' => FORMAT_MOODLE,
                        'incorrectfeedback' => 'Incorrect',
                        'incorrectfeedbackformat' => FORMAT_MOODLE,
                        'answers' => array(
                                1 => (object) array(
                                        'id' => 123,
                                        'answer' => '3',
                                        'answerformat' => 0,
                                        'fraction' => 1,
                                        'tolerance' => 2,
                                        'feedback' => '',
                                        'feedbackformat' => FORMAT_MOODLE,
                                ),
                                2 => (object) array(
                                        'id' => 124,
                                        'answer' => '*',
                                        'answerformat' => 0,
                                        'fraction' => 0,
                                        'tolerance' => 0,
                                        'feedback' => "Completely wrong",
                                        'feedbackformat' => FORMAT_MOODLE,
                                ),
                        ),
                ),
        );

        $output = $this->get_actual_output($qdata);
        $expectedout = $this->get_expected_output($qdata);

        $this->assert_same_qti($expectedout, $output);
    }

    public function test_export_shortanswer() {

        $this->resetAfterTest(true);

        $qdata = (object) array(
                'id' => 666,
                'parent' => 0,
                'name' => 'Shortanswer',
                'questiontext' => "Which is the best animal?",
                'questiontextformat' => FORMAT_MOODLE,
                'generalfeedback' => '',
                'generalfeedbackformat' => FORMAT_MOODLE,
                'defaultmark' => 1,
                'penalty' => 1,
                'length' => 1,
                'qtype' => 'shortanswer',
                'options' => (object) array(
                        'id' => 123,
                        'questionid' => 666,
                        'usecase' => 1,
                        'answers' => array(
                                1 => (object) array(
                                        'id' => 1,
                                        'answer' => 'Frog',
                                        'answerformat' => 0,
                                        'fraction' => 1,
                                        'feedback' => 'Good!',
                                        'feedbackformat' => FORMAT_MOODLE,
                                ),
                                2 => (object) array(
                                        'id' => 2,
                                        'answer' => 'Cat',
                                        'answerformat' => 0,
                                        'fraction' => 0,
                                        'feedback' => "What is it with Moodlers and cats?",
                                        'feedbackformat' => FORMAT_MOODLE,
                                ),
                                3 => (object) array(
                                        'id' => 3,
                                        'answer' => '*',
                                        'answerformat' => 0,
                                        'fraction' => 0,
                                        'feedback' => "Completely wrong",
                                        'feedbackformat' => FORMAT_MOODLE,
                                ),
                        ),
                ),
        );

        $output = $this->get_actual_output($qdata);
        $expectedout = $this->get_expected_output($qdata);

        $this->assert_same_qti($expectedout, $output);
    }

    public function test_export_truefalse() {

        $this->resetAfterTest(true);

        $qdata = (object) array(
                'id' => 666,
                'parent' => 0,
                'name' => 'Q1',
                'questiontext' => "42 is the Absolute Answer to everything.",
                'questiontextformat' => FORMAT_MOODLE,
                'generalfeedback' => '',
                'generalfeedbackformat' => FORMAT_MOODLE,
                'defaultmark' => 1,
                'penalty' => 1,
                'length' => 1,
                'qtype' => 'truefalse',
                'options' => (object) array(
                        'id' => 123,
                        'question' => 666,
                        'trueanswer' => 1,
                        'falseanswer' => 2,
                        'answers' => array(
                                1 => (object) array(
                                        'id' => 123,
                                        'answer' => 'True',
                                        'answerformat' => 0,
                                        'fraction' => 1,
                                        'feedback' => 'You gave the right answer.',
                                        'feedbackformat' => FORMAT_MOODLE,
                                ),
                                2 => (object) array(
                                        'id' => 124,
                                        'answer' => 'False',
                                        'answerformat' => 0,
                                        'fraction' => 0,
                                        'feedback' => "42 is the Ultimate Answer.",
                                        'feedbackformat' => FORMAT_HTML,
                                ),
                        ),
                ),
        );

        $output = $this->get_actual_output($qdata);
        $expectedout = $this->get_expected_output($qdata);

        $this->assert_same_qti($expectedout, $output);
    }

    public function test_export_multianswer() {

        $this->resetAfterTest(true);

        $qdata = (object) array(
                'id' => 48,
                'parent' => 0,
                'name' => 'Cloze',
                'questiontext' => '<p>{#1} is the capital of Germany.<br></p><p>This question consists of some text with an answer embedded right here{#2}<br></p><p>What is 10 divided by 4? {#3}.<br></p>',
                'questiontextformat' => FORMAT_MOODLE,
                'generalfeedback' => '',
                'generalfeedbackformat' => FORMAT_MOODLE,
                'defaultmark' => 3,
                'penalty' => 0,
                'length' => 1,
                'qtype' => 'multianswer',
                'options' => (object) array(
                        'questions' => array(
                                1 => (object) array(
                                        'id' => 49,
                                        'parent' => 48,
                                        'name' => 'Cloze',
                                        'questiontext' => '{1:SHORTANSWER:=Berlin}',
                                        'questiontextformat' => FORMAT_MOODLE,
                                        'generalfeedback' => '',
                                        'generalfeedbackformat' => FORMAT_MOODLE,
                                        'defaultmark' => 1,
                                        'penalty' => 0,
                                        'length' => 1,
                                        'qtype' => 'shortanswer',
                                        'options' => (object) array(
                                                'usecase' => 0,
                                                'answers' => array(
                                                            116 => (object) array(
                                                            'id' => 116,
                                                            'question' => 49,
                                                            'answer' => 'Berlin',
                                                            'answerformat' => 0,
                                                            'fraction' => 1,
                                                            'feedback' => 'You gave the right answer.',
                                                            'feedbackformat' => FORMAT_HTML,
                                                        ),
                                                ),
                                        ),
                                ),
                                2 => (object) array(
                                        'id' => 50,
                                        'parent' => 48,
                                        'name' => 'Cloze',
                                        'questiontext' => '{1:MULTICHOICE:Wrong answer#Feedback for this wrong answer~Another wrong answer#Feedback for the other wrong answer~=Correct answer#Feedback for correct answer}',
                                        'questiontextformat' => FORMAT_MOODLE,
                                        'generalfeedback' => '',
                                        'generalfeedbackformat' => FORMAT_MOODLE,
                                        'defaultmark' => 1,
                                        'penalty' => 0,
                                        'length' => 1,
                                        'qtype' => 'multichoice',
                                        'options' => (object) array(
                                                'single' => 1,
                                                'shuffleanswers' => 0,
                                                'answernumbering' => 0,
                                                'answers' => array(
                                                        117 => (object) array(
                                                                'id' => 117,
                                                                'question' => 50,
                                                                'answer' => 'Wrong answer',
                                                                'answerformat' => 1,
                                                                'fraction' => 0,
                                                                'feedback' => 'Feedback for this wrong answer',
                                                                'feedbackformat' => FORMAT_MOODLE,
                                                        ),
                                                        118 => (object) array(
                                                                'id' => 118,
                                                                'question' => 50,
                                                                'answer' => 'Another wrong answer',
                                                                'answerformat' => 1,
                                                                'fraction' => 0,
                                                                'feedback' => 'Feedback for the other wrong answer',
                                                                'feedbackformat' => FORMAT_MOODLE,
                                                        ),
                                                        119 => (object) array(
                                                                'id' => 119,
                                                                'question' => 50,
                                                                'answer' => 'Correct answer',
                                                                'answerformat' => 1,
                                                                'fraction' => 1,
                                                                'feedback' => 'Feedback for correct answer',
                                                                'feedbackformat' => FORMAT_MOODLE,
                                                        ),
                                                ),
                                        ),
                                ),
                                3 => (object) array(
                                        'id' => 53,
                                        'parent' => 48,
                                        'name' => 'Cloze',
                                        'questiontext' => '{2:NUMERICAL:=2.5:0#Feedback for correct answer}',
                                        'questiontextformat' => FORMAT_MOODLE,
                                        'generalfeedback' => '',
                                        'generalfeedbackformat' => FORMAT_MOODLE,
                                        'defaultmark' => 1,
                                        'penalty' => 0,
                                        'length' => 1,
                                        'qtype' => 'numerical',
                                        'options' => (object) array(
                                                'answers' => array(
                                                        126 => (object) array(
                                                                'id' => 126,
                                                                'question' => 53,
                                                                'answer' => 2.5,
                                                                'answerformat' => 0,
                                                                'fraction' => 1,
                                                                'feedback' => 'Feedback for correct answer',
                                                                'feedbackformat' => FORMAT_HTML,
                                                                'tolerance' => 0,
                                                        ),
                                                ),
                                        ),
                                ),
                        ),

                ),
        );

        $output = $this->get_actual_output($qdata);
        $expectedout = $this->get_expected_output($qdata);

        $this->assert_same_qti($expectedout, $output);
    }
}