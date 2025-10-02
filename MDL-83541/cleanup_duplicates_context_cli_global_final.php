<?php
// File: moodle-global-duplicate-stamp-updater-cli.php
define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../config.php');
global $CFG;
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');
// Add this near the top of your script
ini_set('memory_limit', '8G');  // Increase to 1GB or higher if needed
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

error_reporting(E_ALL);

$validPriorities = [
    'id',
    'idnumber'
];

// CLI options
list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'contextid' => 0,
    'courseid' => 0,
    'categoryid' => 0,
    'originalidentifier' => 'id',
    'force' => false,
    'all' => false,
    'dryrun' => false,
    'produceurls' => false,
    'context-aware' => true, // New option to enable context-aware duplicate detection
    'category-aware' => false // Only questions that are in the same category may be duplicates
], [
    'h' => 'help',
    'x' => 'contextid',
    'c' => 'courseid',
    'g' => 'categoryid',
    'o' => 'originalidentifier',
    'f' => 'force',
    'a' => 'all',
    'd' => 'dryrun',
    'p' => 'produceurls',
    'w' => 'context-aware', // Short option for context-aware
    't' => 'category-aware'
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

// Display help
if ($options['help']) {
    $help = "CLI script to update duplicate question stamps globally in Moodle.

Options:
-h, --help                  Print this help.
-x, --contextid=INT         Process a specific context ID.
-c, --courseid=INT          Process a specific course ID.
-g, --categoryid=INT        Process a specific course category ID.
-o, --originalidentifier=STRING What is used to determine the original question (Values: ".implode(',', $validPriorities).") (default: id)
-a, --all                   Process all contexts in the system.
-f, --force                 Skip confirmation (use with caution).
-d, --dryrun                Show what would be updated without making changes.
-p, --produceurls                  Produce URLs for the originals and duplicates to check stuff beforehand.
-w, --context-aware         Only mark questions as duplicates within the same context (default: true).
-t, --category-aware         Only mark questions as duplicates within the same question category (default: true).

Examples:
\$ php moodle-global-duplicate-stamp-updater-cli.php --contextid=123
\$ php moodle-global-duplicate-stamp-updater-cli.php --courseid=123
\$ php moodle-global-duplicate-stamp-updater-cli.php --categoryid=5
\$ php moodle-global-duplicate-stamp-updater-cli.php --all
\$ php moodle-global-duplicate-stamp-updater-cli.php --all --dryrun
\$ php moodle-global-duplicate-stamp-updater-cli.php --all --no-context-aware
";

    cli_writeln($help);
    exit(0);
}

// Define context level constants if not already defined
if (!defined('CONTEXT_SYSTEM')) define('CONTEXT_SYSTEM', 10);
if (!defined('CONTEXT_USER')) define('CONTEXT_USER', 30);
if (!defined('CONTEXT_COURSECAT')) define('CONTEXT_COURSECAT', 40);
if (!defined('CONTEXT_COURSE')) define('CONTEXT_COURSE', 50);
if (!defined('CONTEXT_MODULE')) define('CONTEXT_MODULE', 70);
if (!defined('CONTEXT_BLOCK')) define('CONTEXT_BLOCK', 80);

// Check if proper options are provided
$paramCount = 0;
if (!empty($options['contextid'])) $paramCount++;
if (!empty($options['courseid'])) $paramCount++;
if (!empty($options['categoryid'])) $paramCount++;
if (!empty($options['all'])) $paramCount++;

if ($paramCount === 0) {
    cli_error('At least one of: context ID (--contextid), course ID (--courseid), category ID (--categoryid), or --all must be specified. Use --help for help.');
}

if ($paramCount > 1) {
    cli_error('Only one of: context ID, course ID, category ID, or all flag can be specified together.');
}

$contextId = (int)$options['contextid'];
$courseId = (int)$options['courseid'];
$categoryId = (int)$options['categoryid'];
$originalPriorities = explode(',', $options['originalidentifier']);
$processAll = (bool)$options['all'];
$force = (bool)$options['force'];
$dryRun = (bool)$options['dryrun'];
$produceUrls = (bool)$options['produceurls'];
$contextAware = (bool)$options['context-aware']; // Get the context-aware setting
$categoryAware = (bool)$options['category-aware'];

if (!$originalPriorities) {
    cli_error('No priorities for selecting the original question given. Cannot continue.');
}
foreach ($originalPriorities as $originalPriority) {
    if (!in_array($originalPriority, $validPriorities)) {
        cli_error('Invalid priority for selecting the original question: '.$originalPriority.'. Valid priorities: '.implode(',', $validPriorities));
    }
}

if ($produceUrls) {
    cli_writeln('<html>');
    cli_writeln('<head>');
    cli_writeln('<meta charset="utf-8">');
    cli_writeln('</head>');
}
if ($dryRun) {
    cli_writeln("DRY RUN MODE: No changes will be made to the database.");
}
if ($produceUrls) {
    cli_writeln("URL GENERATION: Produce urls for both original and duplicates for manual checks.");
}

if ($contextAware) {
    cli_writeln("CONTEXT-AWARE MODE: Only considering questions as duplicates within the same context.");
} else {
    cli_writeln("GLOBAL MODE: Questions can be considered duplicates across different contexts.");
}

if ($categoryAware) {
    cli_writeln("CATEGORY-AWARE MODE: Only considering questions as duplicates within the same question category.");
} else {
    cli_writeln("GLOBAL MODE: Questions can be considered duplicates across different question categories.");
}

// Function to get context name for display
function get_context_name($contextId, $contextLevel, $instanceId) {
    global $DB;

    $name = "Unknown Context {$contextId}";

    switch ($contextLevel) {
        case CONTEXT_SYSTEM:
            $name = "System";
            break;
        case CONTEXT_USER:
            $user = $DB->get_record('user', ['id' => $instanceId]);
            if ($user) {
                $name = "User: {$user->firstname} {$user->lastname}";
            } else {
                $name = "Unknown User";
            }
            break;
        case CONTEXT_COURSECAT:
            $category = $DB->get_record('course_categories', ['id' => $instanceId]);
            if ($category) {
                $name = "Category: {$category->name}";
            } else {
                $name = "Unknown Category";
            }
            break;
        case CONTEXT_COURSE:
            $course = $DB->get_record('course', ['id' => $instanceId]);
            if ($course) {
                $name = "Course: {$course->fullname}";
            } else {
                $name = "Unknown Course";
            }
            break;
        case CONTEXT_MODULE:
            // Get module name and course
            $cm = $DB->get_record('course_modules', ['id' => $instanceId]);
            if ($cm) {
                $module = $DB->get_record('modules', ['id' => $cm->module]);
                $course = $DB->get_record('course', ['id' => $cm->course]);
                $moduleName = "Unknown";

                if ($module) {
                    // Get the actual instance name based on module type
                    $moduleInstance = $DB->get_record($module->name, ['id' => $cm->instance]);
                    if ($moduleInstance && isset($moduleInstance->name)) {
                        $moduleName = $moduleInstance->name;
                    }
                }

                $courseName = $course ? $course->shortname : "Unknown";
                $name = "Module: {$moduleName} in {$courseName}";
            } else {
                $name = "Unknown Module";
            }
            break;
        case CONTEXT_BLOCK:
            $name = "Block {$instanceId}";
            break;
    }

    return "{$name} (Context {$contextId})";
}

function get_url_for_context($contextId, $contextLevel, $instanceId, $qcatId):?moodle_url {
    $params = [
        'cat' => $qcatId.','.$contextId
    ];
    switch ($contextLevel) {
        case CONTEXT_SYSTEM:
            $params['courseid'] = 1;
            break;
        case CONTEXT_COURSECAT:
            $catCourses = get_courses($instanceId);
            foreach ($catCourses as $catCourse) {
                $params['courseid'] = $catCourse->id;
                break;
            }
            break;
        case CONTEXT_COURSE:
            $params['courseid'] = $instanceId;
            break;
        case CONTEXT_MODULE:
            $params['cmid'] = $instanceId;
            break;
    }
    $theUrl = null;
    if (count($params) == 2) {
        $theUrl = new moodle_url('/question/edit.php', $params);
    }
    return $theUrl;
}

// Function to get where clause for context filtering
function get_context_filter($contextId = 0, $courseId = 0, $categoryId = 0) {
    global $DB;

    if ($contextId > 0) {
        // Specific context
        return "ctx.id = {$contextId}";
    } elseif ($courseId > 0) {
        // Course context and its children
        $courseContext = $DB->get_record('context', ['contextlevel' => CONTEXT_COURSE, 'instanceid' => $courseId]);
        if (!$courseContext) {
            cli_error("Course with ID {$courseId} not found or has no context.");
        }
        return "ctx.path LIKE '{$courseContext->path}/%' OR ctx.id = {$courseContext->id}";
    } elseif ($categoryId > 0) {
        // Category context and its children
        $catContext = $DB->get_record('context', ['contextlevel' => CONTEXT_COURSECAT, 'instanceid' => $categoryId]);
        if (!$catContext) {
            cli_error("Category with ID {$categoryId} not found or has no context.");
        }
        return "ctx.path LIKE '{$catContext->path}/%' OR ctx.id = {$catContext->id}";
    } else {
        // All contexts
        return "1=1";
    }
}

function getGroupKey($question, $contextAware, $categoryAware) {
    $key = $question->question_stamp;
    $key .= $contextAware ? '_ctx'.$question->context_id : '';
    $key .= $categoryAware ? '_qcat'.$question->category_id : '';
    return $key;
}

function getFilterMessage($contextId = 0, $courseId = 0, $categoryId = 0) {
    global $DB;
    if ($contextId > 0) {
        $context = $DB->get_record('context', ['id' => $contextId]);
        if (!$context) {
            cli_error("Context with ID {$contextId} not found.");
        }
        $filterMsg = "specific context: " . get_context_name($contextId, $context->contextlevel, $context->instanceid);
    } elseif ($courseId > 0) {
        $course = $DB->get_record('course', ['id' => $courseId]);
        if (!$course) {
            cli_error("Course with ID {$courseId} not found.");
        }
        $filterMsg = "course context: {$course->fullname} (ID: {$courseId}) and its child contexts";
    } elseif ($categoryId > 0) {
        $category = $DB->get_record('course_categories', ['id' => $categoryId]);
        if (!$category) {
            cli_error("Category with ID {$categoryId} not found.");
        }
        $filterMsg = "category context: {$category->name} (ID: {$categoryId}) and its child contexts";
    } else {
        $filterMsg = "all contexts in the system";
    }
    return $filterMsg;
}

function getAllQuestions($contextId, $courseId, $categoryId) {
    global $DB;
    // Build context filter
    $contextFilter = get_context_filter($contextId, $courseId, $categoryId);
    try {
        // Get all questions without ROW_NUMBER() partitioning
        $sql = "SELECT 
                q.id AS question_id,
                q.parent AS question_parent,
                q.name AS question_name,
                q.qtype AS question_type,
                q.stamp AS question_stamp,
                qc.id AS category_id,
                qc.name AS category_name,
                qbe.idnumber AS bankentry_idnumber,
                ctx.id AS context_id,
                ctx.contextlevel AS context_level,
                ctx.instanceid AS instance_id,
                ctx.path AS context_path
            FROM 
                {question} q
            JOIN 
                {question_versions} qv ON q.id = qv.questionid
            JOIN 
                {question_bank_entries} qbe ON qv.questionbankentryid = qbe.id
            JOIN 
                {question_categories} qc ON qbe.questioncategoryid = qc.id
            JOIN 
                {context} ctx ON qc.contextid = ctx.id
            WHERE 
                {$contextFilter}
            AND q.qtype <> 'random'
            ORDER BY
                q.stamp, q.id
            ";
        $allQuestions = $DB->get_records_sql($sql);
    } catch (Exception $e) {
        cli_writeln("Error fetching questions: " . $e->getMessage());
        return [0, 0];
    }
    return $allQuestions;
}

function groupDuplicateQuestions(array $allQuestions, bool $contextAware, bool $categoryAware, array $originalPriorities):array {
    $questionsByStamp = [];
    foreach ($allQuestions as $q) {
        $key = getGroupKey($q, $contextAware, $categoryAware);
        $q->url = get_url_for_context(
            $q->context_id,
            $q->context_level,
            $q->instance_id,
            $q->category_id
        );
        if (!isset($questionsByStamp[$key])) {
            $questionsByStamp[$key] = [
                'stamp' => $q->question_stamp,
                'context_id' => $contextAware ? $q->context_id : null,
                'category_id' => $categoryAware ? $q->category_id : null,
                'original_question' => null,
                'has_duplicates' => false,
                'question_ids' => [],
                'questions' => [],
                'duplicates' => [],
                'questions_with_parents' => [],
                'questions_with_idnumbers' => []
            ];
        } else {
            $questionsByStamp[$key]['has_duplicates'] = true;
        }
        $questionsByStamp[$key]['question_ids'][] = $q->question_id;
        $questionsByStamp[$key]['questions'][] = $q;
        if ($q->question_parent) {
            $questionsByStamp[$key]['questions_with_parents'][] = $q;
        }
        if ($q->bankentry_idnumber) {
            $questionsByStamp[$key]['questions_with_idnumbers'][] = $q;
        }
    }
    $questionsWithDuplicates = array_filter($questionsByStamp, function($group) {
        return $group['has_duplicates'];
    });

    $removedStamps = [];
    foreach ($questionsWithDuplicates as $key => &$info) {
        $fakeDuplicates = [];
        $fakeDuplicateIds = [];
        foreach ($info['questions_with_parents'] as $q) {
            if (in_array($q->question_parent, $info['question_ids'])) {
                $fakeDuplicates[] = $q;
                $fakeDuplicateIds[] = $q->question_id;
            }
        }
        if ($fakeDuplicates) {
            $newInfoQuestions = [];
            foreach ($info['questions'] as $infoQuestion) {
                if (!in_array($infoQuestion->question_id, $fakeDuplicateIds)) {
                    $newInfoQuestions[] = $infoQuestion;
                }
            }
            $info['questions'] = $newInfoQuestions;

            $newInfoQuestionsWithParents = [];
            foreach ($info['questions_with_parents'] as $infoQuestionWithParent) {
                if (!in_array($infoQuestionWithParent->question_id, $fakeDuplicateIds)) {
                    $newInfoQuestionsWithParents[] = $infoQuestionWithParent;
                }
            }
            $info['questions_with_parents'] = $newInfoQuestionsWithParents;

            $info['question_ids'] = array_values(array_diff($info['question_ids'], $fakeDuplicateIds));
        }
        if (count($info['question_ids']) == 1) {
            $removedStamps[] = $key;
            continue;
        }
        $originalQuestion = null;
        foreach ($originalPriorities as $originalPriority) {
            switch ($originalPriority) {
                case 'id':
                    $originalQuestion = $info['questions'][0];
                    break;
                case 'idnumber':
                    if ($info['questions_with_idnumbers']) {
                        $originalQuestion = $info['questions_with_idnumbers'][0];
                        break;
                    }
                    break;
                default:
                    cli_error('groupDuplicateQuestions: This should never happen, encountered: '.$originalPriority);
            }
            if ($originalQuestion) {
                $info['original_question'] = $originalQuestion;
                break;
            }
        }
        if (!$originalQuestion) {
            cli_error('Could not identify original question for stamp group :'.$key);
        }
        $dups = [];
        foreach ($info['questions'] as $q) {
            if ($q->question_id != $originalQuestion->question_id) {
                $dups[] = $q;
            }
        }
        $info['duplicates'] = $dups;
        foreach ($info['duplicates'] as $index => $dup) {
            $rowNum = $index + 1;
            $dup->row_num = $rowNum;
            $dup->newstamp = 'dup'.($rowNum).'.'.$dup->question_stamp;
            $dup->newname = $dup->question_name.' (duplicate '.($rowNum).')';
        }
    }
    foreach ($removedStamps as $removedStamp) {
        unset($questionsWithDuplicates[$removedStamp]);
    }
    return $questionsWithDuplicates;
}

function countQuestionAttempts($questionid) {
    global $DB;
    return $DB->count_records('question_attempts', ['questionid' => $questionid]);
}

function countQuizzesWithQuestion($questionId) {
    global $DB;
    $sql = "SELECT COUNT(DISTINCT qs.quizid) 
                        FROM {quiz_slots} qs 
                        WHERE qs.id IN (
                            SELECT qsr.itemid 
                            FROM {question_references} qsr
                            WHERE qsr.questionbankentryid IN (
                                SELECT qv.questionbankentryid
                                FROM {question_versions} qv
                                WHERE qv.questionid = :questionid
                            ) AND qsr.component = 'mod_quiz'
                        )";
    return $DB->count_records_sql($sql, ['questionid' => $questionId]);
}

function countStudentquizzesWithQuestion($questionId) {
    global $DB;
    $sql = "SELECT COUNT(*) FROM {question_references} qr"
        ." JOIN {question_versions} qv ON qr.questionbankentryid = qv.questionbankentryid"
        ." WHERE qr.component = 'mod_studentquiz'"
        ." AND qv.questionid = :questionid";
    $params = [
        'questionid' => $questionId
    ];
    return $DB->count_records_sql($sql, $params);
}

// Function to find and update duplicate stamps
function process_duplicates(
    $contextId = 0,
    $courseId = 0,
    $categoryId = 0,
    $originalPriorities = [],
    $force = false,
    $dryRun = false,
    $produceUrls = false,
    $contextAware = true,
    $categoryAware = false
) {
    cli_writeln("\n" . str_repeat("=", 80));

    // Set context filter message
    $filterMsg = getFilterMessage($contextId, $courseId, $categoryId);
    cli_writeln("FINDING DUPLICATES IN: {$filterMsg}");
    cli_writeln(str_repeat("=", 80) . "\n");

    // First get all questions that match our criteria
    $allQuestions = getAllQuestions($contextId, $courseId, $categoryId);
    if (!$allQuestions) {
        cli_writeln("No questions found with the current filter criteria.");
        return [0, 0];
    }

    cli_writeln("SQL query completed, starting to process results...");
    cli_writeln("Found " . count($allQuestions) . " questions total.");

    // Process questions to find duplicates
    $duplicateGroups = groupDuplicateQuestions($allQuestions, $contextAware, $categoryAware, $originalPriorities);

    if (empty($duplicateGroups)) {
        cli_writeln("No questions with duplicates found. Exiting.");
        return [0, 0];
    }

    $foundText = "Found ".count($duplicateGroups)." groups of questions with duplicate stamps";
    $foundText .= $contextAware ? " within the same context" : " across all contexts";
    $foundText .= $categoryAware ? " within the same question category" : " across all question categories";
    cli_writeln($foundText);

    // Process duplicates to standardized format
    $questionDuplicatesById = [];
    $groupKeys = [];
    $duplicatesGroupedByContext = [];

    foreach ($duplicateGroups as $group) {
        foreach ($group['duplicates'] as $dup) {
            $questionDuplicatesById[$dup->question_id] = $dup;
            $key = getGroupKey($dup, $contextAware, $categoryAware);
            if (!in_array($key, $groupKeys)) {
                $groupKeys[] = $key;
            }
            $contextKey = $dup->context_id;
            if (!isset($duplicatesGroupedByContext[$contextKey])) {
                $duplicatesGroupedByContext[$contextKey] = [
                    'context_id' => $dup->context_id,
                    'context_level' => $dup->context_level,
                    'instance_id' => $dup->instance_id,
                    'original_question' => $group['original_question'],
                    'duplicates' => []
                ];
            }
            $duplicatesGroupedByContext[$contextKey]['duplicates'][] = $dup;
        }
    }

    $nrDuplicates = count($questionDuplicatesById);
    $nrGroups = count($groupKeys);
    cli_writeln("- Total stamps with duplicates: " . $nrGroups);
    cli_writeln("- Total questions (originals + duplicates): " .($nrDuplicates + $nrGroups));
    cli_writeln("- Duplicate questions which need updates: " . $nrDuplicates);
    cli_writeln("- Contexts with duplicates: " . count($duplicatesGroupedByContext));

    // If no duplicates need updates, skip processing
    if (empty($questionDuplicatesById)) {
        cli_writeln("\nNo questions need stamp updates. Skipping.");
        return [count($groupKeys), 0];
    }

    // Display details about each context with duplicates
    cli_writeln("\nDuplicates by Context:");
    foreach ($duplicatesGroupedByContext as $contextData) {
        $contextName = get_context_name(
            $contextData['context_id'],
            $contextData['context_level'],
            $contextData['instance_id']
        );
        $nrDupsInContext = count($contextData['duplicates']);

        cli_writeln("- {$contextName}");
        cli_writeln("  * Duplicates requiring updates: {$nrDupsInContext}");
    }

    // Display more details about duplicates
    cli_writeln("\nDetailed duplicate information (showing first 10 stamp groups):");

    // Limit the detailed output to avoid overwhelming terminal
    $counter = 0;
    foreach ($duplicateGroups as $info) {
        if ($counter++ >= 10) {
            cli_writeln("\n... and " . ($nrGroups - 10) . " more stamp groups");
            break;
        }

        $firstDuplicate = $info['duplicates'][0];
        $originalQuestion = $info['original_question'];
        // Extract context info from first question for display
        $contextInfo = get_context_name(
            $firstDuplicate->context_id,
            $firstDuplicate->context_level,
            $firstDuplicate->instance_id
        );

        cli_writeln("\nDuplicates with stamp: {$firstDuplicate->question_stamp} in {$contextInfo}");
        cli_writeln("- [{$originalQuestion->question_id}] {$originalQuestion->question_name} [ORIGINAL]");
        foreach (array_slice($info['duplicates'], 0, 5) as $dup) {
            cli_writeln("- [{$dup->question_id}] {$dup->question_name} [NEEDS UPDATE]");
        }

        $nrGroupDuplicates = count($info['duplicates']);
        if ($nrGroupDuplicates > 5) {
            cli_writeln("- ... and " . ($nrGroupDuplicates - 5) . " more questions with this stamp");
        }
    }

    if ($produceUrls) {
        cli_writeln('<table>');
        cli_writeln('<thead>');
        cli_writeln('<th scope="col">Course ID</th>');
        cli_writeln('<th scope="col">Course Link</th>');
        cli_writeln('<th scope="col">Questiontype</th>');
        cli_writeln('<th scope="col">Question Category Link</th>');
        cli_writeln('<th scope="col">Question Idnumber (Original)</th>');
        cli_writeln('<th scope="col">Question Name (Original)</th>');
        cli_writeln('<th scope="col">#Duplicates</th>');
        cli_writeln('<th scope="col">Question Idnumber (Duplicate)</th>');
        cli_writeln('<th scope="col">Question Name (Duplicate)</th>');
        cli_writeln('<th scope="col">Check 1 "#Attempts"</th>');
        cli_writeln('<th scope="col">Check 2 "#Quizzes"</th>');
        cli_writeln('<th scope="col">Check 3 "#Student quizzes"</th>');
        cli_writeln('<th scope="col">Check 4 "Random qtype?"</th>');
        cli_writeln('</thead>');
        cli_writeln('<tbody>');
        $courses = [];
        foreach ($duplicateGroups as $duplicateGroup) {
            $originalQuestion = $duplicateGroup['original_question'];
            $nrGroupDuplicates = count($duplicateGroup['duplicates']);
            foreach ($duplicateGroup['duplicates'] as $restDup) {
                cli_writeln('<tr>');
                $courseId = ($restDup->context_level == CONTEXT_COURSE) ? $restDup->instance_id : 0;
                cli_writeln('<td>'.$courseId.'</td>');
                $linkText = '<td>Frage nicht im Kurskontext, sondern in Level '.$restDup->context_level.'</td>';
                if ($courseId) {
                    if (!isset($courses[$courseId])) {
                        $course = get_course($courseId);
                        $courses[$courseId] = $course;
                    }
                    $course = $courses[$courseId];
                    $courseLink = new moodle_url('/course/view.php', ['id' => $courseId]);
                    $linkText = '<td><a href="'.$courseLink->out(false).'">'.$course->fullname.'</td>';
                }
                cli_writeln($linkText);
                cli_writeln('<td>'.$originalQuestion->question_type.'</td>');
                cli_writeln('<td><a href="'.$originalQuestion->url->out(false).'">'.$originalQuestion->category_name.'</a></td>');
                cli_writeln('<td>'.$originalQuestion->bankentry_idnumber.'</td>');
                cli_writeln('<td>'.$originalQuestion->question_name.'</td>');
                cli_writeln('<td>'.$nrGroupDuplicates.'</td>');
                cli_writeln('<td>'.$restDup->bankentry_idnumber.'</td>');
                cli_writeln('<td>'.$restDup->question_name.'</td>');
                cli_writeln('<td>'.countQuestionAttempts($restDup->question_id).'</td>');
                cli_writeln('<td>'.countQuizzesWithQuestion($restDup->question_id).'</td>');
                cli_writeln('<td>'.countStudentquizzesWithQuestion($restDup->question_id).'</td>');
                $isRandom = $restDup->question_type == 'random' ? 'ja': 'nein';
                cli_writeln('<td>'.$isRandom.'</td>');
                cli_writeln('</tr>');
            }
        }
        cli_writeln('</tbody>');
        cli_writeln('</table>');
    }

    // Ask for confirmation before proceeding
    if (!$force && !$dryRun && !$produceUrls) {
        $prompt = "Are you sure you want to update ".$nrDuplicates." question stamps? (y/N)";
        readline_callback_handler_install($prompt, function() {});
        $input = stream_get_contents(STDIN, 1);
        cli_writeln('');
        if (!$input || !preg_match('/^y$/i', $input)) {
            cli_writeln("Update canceled.");
            return [$nrGroups, 0];
        }
    }

    // Exit if dry run
    if ($dryRun || $produceUrls) {
        cli_writeln("\nDRY RUN/URL Production Only - No changes were made. Re-run without --dryrun to apply changes.");
        return [$nrGroups, $nrDuplicates];
    }

    // Process duplicate stamp updates
    $updatedCount = 0;
    $errors = [];

    cli_writeln("\nUpdating question stamps:");

    global $DB;
    try {
        $counter = 0;

        foreach ($questionDuplicatesById as $duplicate) {
            $counter++;
            $progress = round(($counter / $nrDuplicates) * 100);
            $contextName = get_context_name($duplicate->context_id, $duplicate->context_level, $duplicate->instance_id);

            cli_writeln("[" . str_pad($progress . "%", 5) . "] Updating question {$duplicate->question_id}: {$duplicate->question_name}");
            cli_writeln("    In context: {$contextName}");
            cli_writeln("    New name: {$duplicate->newname}");
            cli_writeln("    New stamp: {$duplicate->newstamp}");

            // Update the stamp and name for each duplicate
            $updateFields = new stdClass();
            $updateFields->id = $duplicate->question_id;
            $updateFields->stamp = $duplicate->newstamp;
            $updateFields->name = $duplicate->newname;

            $result = $DB->update_record('question', $updateFields);
            if ($result) {
                $updatedCount++;
            } else {
                $errors[] = "Failed to update question ID {$duplicate->question_id}";
                cli_writeln("    ERROR: Failed to update record");
            }
        }

        cli_writeln("\nCompleted: Successfully updated {$updatedCount} of {$nrDuplicates} question stamps.");
    } catch (Exception $e) {
        cli_writeln("Error during update: " . $e->getMessage());
        return [$nrGroups, 0];
    }

    // Show any errors
    if (!empty($errors)) {
        cli_writeln("\nWarning: Some questions could not be updated:");
        foreach ($errors as $error) {
            cli_writeln("- " . $error);
        }
    }

    return [$nrGroups, $updatedCount];
}

// Execute the main function
try {
    list($duplicateCount, $updatedCount) = process_duplicates(
        $contextId,
        $courseId,
        $categoryId,
        $originalPriorities,
        $force,
        $dryRun,
        $produceUrls,
        $contextAware,
        $categoryAware
    );

    // Display final statistics
    cli_writeln("\n" . str_repeat("=", 80));
    cli_writeln("FINAL STATISTICS");
    cli_writeln(str_repeat("=", 80));
    cli_writeln("Total stamps with duplicates: " . $duplicateCount);
    if ($dryRun) {
        cli_writeln("Total questions that would be updated: " . $updatedCount);
        cli_writeln("DRY RUN - No changes were made");
    } else {
        cli_writeln("Total questions updated: " . $updatedCount);
    }
    cli_writeln("Context-aware mode: " . ($contextAware ? "ON (only considered duplicates within same context)" : "OFF (considered duplicates across all contexts)"));
    if ($produceUrls) {
        cli_writeln('</html>');
    }

    exit(0);
} catch (Exception $e) {
    cli_error("Error: " . $e->getMessage());
}
