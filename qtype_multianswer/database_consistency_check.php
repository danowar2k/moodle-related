<?php

require_once(__DIR__.'/../config.php');

global $DB;

require_admin();

$questionsWithProblems = [];
$subquestionsWithProblems = [];
$missingQuestions = [];

echo "Checking qtype_multianswer database consistency...<br/>";
echo "<br/>";
echo "1) Does every multianswer record have a matching question record?<br/>";

$nrQuestionRecords = $DB->count_records_sql("SELECT count(*) FROM {question} q WHERE q.qtype = 'multianswer'");
echo '1) Nr of "multianswer" question records: '. $nrQuestionRecords. '<br/>';

$nrMultianswerRecords = $DB->count_records('question_multianswer');
echo '1) Nr of question_multianswer records: '. $nrMultianswerRecords. '<br/>';

$multianswersMissingQuestionsSql =
    "SELECT qma.* FROM {question_multianswer} qma
        LEFT JOIN {question} q ON qma.question = q.id
        WHERE q.id IS NULL";
$qmaMissingQuestions = $DB->get_records_sql($multianswersMissingQuestionsSql);
foreach ($qmaMissingQuestions as $qmaMissingQuestion) {
    $missingQuestions[] = $qmaMissingQuestion->question;
    echo "1) Multianswer record with missing question: ".$qmaMissingQuestion->id."<br/>";
}

echo "<br/>";
echo "2) Multianswer questions should probably not have a parent. Checking...<br/>";

$noParentSql = "SELECT * FROM {question} q WHERE q.qtype = 'multianswer' AND q.parent <> 0";
$maQuestionsWithParent = $DB->get_records_sql($noParentSql);
if (!$maQuestionsWithParent) {
    echo "2) OK. No MA questions that have a parent but should not<br/>";
} else {
    foreach ($maQuestionsWithParent as $maQuestionWithParent) {
        $questionsWithProblems[] = $maQuestionWithParent->id;
        echo "Multianswer question with a parent: " . $maQuestionWithParent->id . "<br/>";
    }
}

echo "<br/>";
echo "3) Multianswer record checks...<br/>";
echo "3a) Every multianswer needs a sequence<br/>";
echo "3b) Every multianswer sequence needs to be comma-separated numbers<br/>";
echo "3c) Every id in a multianswer sequence needs a corresponding question record (subquestion)<br/>";
echo "3d) Every subquestion record needs to have the correct multianswer question as parent<br/>";
echo "<br/>";
$multianswers = $DB->get_records('question_multianswer');

foreach ($multianswers as $multianswer) {
    $maId = $multianswer->id;
    $maQuestionId = $multianswer->question;
    if (!isset($multianswer->sequence) || is_null($multianswer->sequence) || !$multianswer->sequence) {
        echo "3a) MA record without a sequence found: ".$maId."<br/>";
        $questionsWithProblems[] = $maQuestionId;
        continue;
    }
    $maSequence = $multianswer->sequence;
    $maSequenceQuestionIds = explode(',', $maSequence);
    if (!$maSequenceQuestionIds || preg_match("/^,+$/", $maSequence)) {
        $questionsWithProblems[] = $maQuestionId;
        echo "3b) Bad sequence for MA (id: $maId, question: $maQuestionId) found ($maSequence)<br/>";
        continue;
    }
    $sequenceCount = count($maSequenceQuestionIds);

    $subQuestionSql = "SELECT q.* FROM {question} q WHERE q.id IN($maSequence)";
    $subquestions = $DB->get_records_sql($subQuestionSql);
    $subQuestionCount = count($subquestions);
    if ($sequenceCount !== $subQuestionCount) {
        $questionsWithProblems[] = $maQuestionId;
        echo "3c) MA with id: $maId, question: $maQuestionId<br/>";
        echo "3c) Has sequence of ".$sequenceCount." subquestions (".$maSequence.")<br/>";
        echo "3c) But found only ".$subQuestionCount." of them in database<br/>";
    }
    foreach ($subquestions as $subquestion) {
        $subquestionId = $subquestion->id;
        $subquestionParent = $subquestion->parent;
        if ($subquestionParent !== $maQuestionId) {
            $questionsWithProblems[] = $maQuestionId;
            $subquestionsWithProblems[] = $subquestionId;
            echo "3d) Subquestion $subquestionId is in sequence of MA with ID $maId but has the wrong parent $subquestionParent (correct: $maQuestionId)<br/>";
        }
    }
}
$questionsWithProblems = array_unique($questionsWithProblems);
$subquestionsWithProblems = array_unique($subquestionsWithProblems);

$nrQuestionsWithProblems = count($questionsWithProblems);
$nrSubquestionsWithProblems = count($subquestionsWithProblems);

echo "Multianswer questions with problems: $nrQuestionsWithProblems<br/>";
echo "Multianswer subquestion questions with problems: $nrSubquestionsWithProblems<br/>";

if ($questionsWithProblems) {
    echo "<br/>";
    $sql = "SELECT q.id as questionid, c.id as courseid FROM {question} q
    JOIN {question_versions} qv ON q.id = qv.questionid
    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
    JOIN {context} ctx ON ctx.id = qc.contextid
    JOIN {course} c ON c.id = ctx.instanceid
    WHERE ctx.contextlevel = 50
    AND q.id IN(".implode(",", $questionsWithProblems).")";

    $questionsAndCourses = $DB->get_records_sql($sql);
    $nrResults = count($questionsAndCourses);
    $foundQuestions = array_keys($questionsAndCourses);
    $missingQuestions = array_diff($questionsWithProblems, $foundQuestions);

    if ($nrQuestionsWithProblems > $nrResults) {
        echo "Warning! There are problematic questions not directly in a course.<br/>";
        echo "Question IDs not in a course: ".implode(",", $missingQuestions)."<br/>";
    }
    foreach ($questionsAndCourses as $questionAndCourse) {
        $params = [
            'id' => $questionAndCourse->questionid,
            'courseid' => $questionAndCourse->courseid
        ];
        $url = new moodle_url('/question/bank/previewquestion/preview.php', $params);
        echo "<a target='_blank' href='".$url->out()."'>Link to problematic question $questionAndCourse->questionid</a><br/>";
    }
}
