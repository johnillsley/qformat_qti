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
 * Template used to build xml file for QTI format question
 *
 * @package    qformat_qti
 * @author     John Illsley <j.s.illsley@bath.ac.uk>
 * @copyright  2018 University of Bath
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Builds the xml needed for a qti question using a template that contains all common components used for all question types
 * @param object $xmlparam containing parameters to be inserted into xml template
 * @return string xml content
 */

defined('MOODLE_INTERNAL') || die();

function qti_export_build_xml($xmlparam) {

    $xml = '<?xml version="1.0" encoding="UTF-8"?>
<assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1"
xsi:schemaLocation="http://www.imsglobal.org/xsd/imsqti_v2p1  http://www.imsglobal.org/xsd/qti/qtiv2p1/imsqti_v2p1.xsd"
identifier="'.$xmlparam->question_id.'"
title="'.$xmlparam->question_title.'"
adaptive="false"
timeDependent="false"
xmlns:java="http://xml.apache.org/xalan/java"
xmlns:imsmd="http://www.imsglobal.org/xsd/imsmd_v1p2"
xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
'.$xmlparam->response_declaration.'

  <outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float">
    <defaultValue>
      <value>0</value>
    </defaultValue>
  </outcomeDeclaration>

  <outcomeDeclaration identifier="FEEDBACK" cardinality="single" baseType="identifier"/>
'.$xmlparam->output_declaration.'

  <templateDeclaration identifier="SCORE_EACH_CORRECT" cardinality="single" baseType="float">
    <defaultValue><value>1</value></defaultValue>
  </templateDeclaration>
  <templateDeclaration identifier="SCORE_EACH_WRONG" cardinality="single" baseType="float">
    <defaultValue><value>0</value></defaultValue>
  </templateDeclaration>
  <templateDeclaration identifier="SCORE_ALL_CORRECT" cardinality="single" baseType="float">
    <defaultValue><value/></defaultValue>
  </templateDeclaration>
    <templateDeclaration identifier="SCORE_MINIMUM" cardinality="single" baseType="float">
  <defaultValue><value/></defaultValue>
    </templateDeclaration>
  <templateDeclaration identifier="SCORE_UNANSWERED" cardinality="single" baseType="float">
    <defaultValue><value>0</value></defaultValue>
  </templateDeclaration>

  <itemBody inspera:defaultLanguage="en_us" inspera:supportedLanguages="en_us" xmlns:inspera="http://www.inspera.no/qti">
'.$xmlparam->question_text.'
'.$xmlparam->question_interaction.'
  </itemBody>

  <responseProcessing>

'.$xmlparam->response_condition_score.'

    <responseCondition>
      <responseIf>
        <and>
'.$xmlparam->response_fb_unasnswered.'
        </and>
        <setOutcomeValue identifier="FEEDBACK">
          <baseValue baseType="identifier">feedback_unanswered</baseValue>
        </setOutcomeValue>
      </responseIf>
      <responseElseIf>
        <and>
'.$xmlparam->response_fb_correct.'
        </and>
        <setOutcomeValue identifier="FEEDBACK">
          <baseValue baseType="identifier">feedback_correct</baseValue>
        </setOutcomeValue>
      </responseElseIf>
      <responseElseIf>
        <or>
'.$xmlparam->response_fb_partially.'
        </or>
        <setOutcomeValue identifier="FEEDBACK">
          <baseValue baseType="identifier">feedback_partially_correct</baseValue>
        </setOutcomeValue>
      </responseElseIf>
      <responseElse>
        <setOutcomeValue identifier="FEEDBACK">
          <baseValue baseType="identifier">feedback_wrong</baseValue>
        </setOutcomeValue>
      </responseElse>
    </responseCondition>

    <responseCondition inspera:type="max_score_upper_bound" xmlns:inspera="http://www.inspera.no/qti">
	  <responseIf>
	    <and>
		  <gte>
		    <variable identifier="SCORE"/>
			<baseValue baseType="float">'.$xmlparam->question_max_score.'</baseValue>
		  </gte>
		</and>
		<setOutcomeValue identifier="SCORE">
		  <baseValue baseType="float">'.$xmlparam->question_max_score.'</baseValue>
		</setOutcomeValue>
	  </responseIf>
	</responseCondition>

  </responseProcessing>

  <modalFeedback outcomeIdentifier="FEEDBACK" identifier="feedback_unanswered" showHide="show"></modalFeedback>
  <modalFeedback outcomeIdentifier="FEEDBACK" identifier="feedback_wrong" showHide="show">'
            .$xmlparam->question_incorrect_feedback.'</modalFeedback>
  <modalFeedback outcomeIdentifier="FEEDBACK" identifier="feedback_correct" showHide="show">'
            .$xmlparam->question_correct_feedback.'</modalFeedback>
  <modalFeedback outcomeIdentifier="FEEDBACK" identifier="feedback_partially_correct" showHide="show">'
            .$xmlparam->question_partially_correct_feedback.'</modalFeedback>
</assessmentItem>';

    return $xml;
}