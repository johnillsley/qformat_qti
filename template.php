<?php
function qti_export_build_xml($xml_param) {

    $xml = '<?xml version="1.0" encoding="UTF-8"?>
<assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" xsi:schemaLocation="http://www.imsglobal.org/xsd/imsqti_v2p1  http://www.imsglobal.org/xsd/qti/qtiv2p1/imsqti_v2p1.xsd" identifier="'.$xml_param->question_id.'" title="'.$xml_param->question_title.'" adaptive="false" timeDependent="false" xmlns:java="http://xml.apache.org/xalan/java" xmlns:imsmd="http://www.imsglobal.org/xsd/imsmd_v1p2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
'.$xml_param->response_declaration.'

  <outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float">
    <defaultValue>
      <value>0</value>
    </defaultValue>
  </outcomeDeclaration>
    
  <outcomeDeclaration identifier="FEEDBACK" cardinality="single" baseType="identifier"/>
'.$xml_param->output_declaration.'
    
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
'.$xml_param->question_text.'
'.$xml_param->question_interaction.'
  </itemBody>

  <responseProcessing>
  
'.$xml_param->response_condition_score.'
          
    <responseCondition>
      <responseIf>
        <and>
'.$xml_param->response_fb_unasnswered.'
        </and>
        <setOutcomeValue identifier="FEEDBACK">
          <baseValue baseType="identifier">feedback_unanswered</baseValue>
        </setOutcomeValue>
      </responseIf>
      <responseElseIf>
        <and>
'.$xml_param->response_fb_correct.'
        </and>
        <setOutcomeValue identifier="FEEDBACK">
          <baseValue baseType="identifier">feedback_correct</baseValue>
        </setOutcomeValue>
      </responseElseIf>   
      <responseElseIf>
        <or>
'.$xml_param->response_fb_partially.'
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
			<baseValue baseType="float">'.$xml_param->question_max_score.'</baseValue>
		  </gte>
		</and>
		<setOutcomeValue identifier="SCORE">
		  <baseValue baseType="float">'.$xml_param->question_max_score.'</baseValue>
		</setOutcomeValue>
	  </responseIf>
	</responseCondition>
	
  </responseProcessing>

  <modalFeedback outcomeIdentifier="FEEDBACK" identifier="feedback_unanswered" showHide="show"></modalFeedback>
  <modalFeedback outcomeIdentifier="FEEDBACK" identifier="feedback_wrong" showHide="show">'.$xml_param->question_incorrect_feedback.'</modalFeedback>
  <modalFeedback outcomeIdentifier="FEEDBACK" identifier="feedback_correct" showHide="show">'.$xml_param->question_correct_feedback.'</modalFeedback>
  <modalFeedback outcomeIdentifier="FEEDBACK" identifier="feedback_partially_correct" showHide="show">'.$xml_param->question_partially_correct_feedback.'</modalFeedback>  
</assessmentItem>';

    return $xml;
}
?>