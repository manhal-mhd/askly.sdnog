<?php
// make_daily_quizzes.php
// CLI script to create 30 quizzes (one per day) and attach questions by name.
// Place this file in your Moodle server (e.g. /var/www/moodle/local/scripts/) and run:
// sudo -u www-data php /var/www/moodle/local/scripts/make_daily_quizzes.php

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$CFG_PATH = '/var/www/moodle/config.php';
if (!file_exists($CFG_PATH)) {
    echo "Cannot find Moodle config.php at $CFG_PATH\n";
    exit(1);
}

if (!defined('CLI_SCRIPT')) {
    define('CLI_SCRIPT', true);
}

// Increase limits for long-running operations
@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once($CFG_PATH);
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
// Include locallib for quiz APIs (quiz_add_quiz_question etc.).
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
// Ensure functions for adding modules are available.
require_once($CFG->dirroot . '/course/modlib.php');

// Include question API - different Moodle versions store it in different locations.
$questionlibpaths = [
    $CFG->dirroot . '/question/api/lib.php',
    $CFG->dirroot . '/question/engine/lib.php',
    $CFG->dirroot . '/question/questionlib.php',
    $CFG->dirroot . '/lib/questionlib.php'
];
$included = false;
foreach ($questionlibpaths as $p) {
    if (file_exists($p)) {
        require_once($p);
        $included = true;
        break;
    }
}
if (!$included) {
    throw new Exception('Could not locate Moodle question library (tried: ' . implode(', ', $questionlibpaths) . ')');
}

global $DB, $CFG;

// CONFIGURATION - change as-needed
$courseid = 2; // provided by you
$giftpath = '/var/www/moodle/Daily_Network_Security_Challenge.gift';
// Start date set dynamically to tomorrow (server local time)
$startdate = date('Y-m-d', strtotime('tomorrow'));
$openhour = 17; // 17 -> 5pm local server time
$durationseconds = 3600; // quizzes open for 1 hour
$timelimitseconds = 300; // 5 minutes
$attemptsallowed = 1;
$unlock_method = 'completion'; // currently we will set activity completion: require submission

// names used when importing GIFT (we used these when generating the .gift file)
$question_title_prefix = 'Day '; // followed by '01 - ...'

// Ensure course exists
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Check question import existence
if (!file_exists($giftpath)) {
    echo "Warning: gift file not found at $giftpath\n";
    echo "This script will still attempt to create quizzes, but questions must exist in the question bank (import GIFT first).\n";
}

// Helper: zero-pad day
function daypad($i) {
    return sprintf('%02d', $i);
}

// Create a category for these quizzes (optional)
$sectionname = 'Daily Network Security Challenge';
$coursecontext = context_course::instance($courseid);

// For controlling review visibility until after all quizzes: we'll set quiz review/grade options conservatively (no review after attempt)

for ($i = 1; $i <= 30; $i++) {
    $day = daypad($i);
    $quizname = "Day $day - Daily Network Security Challenge";

    // Compute open/close times
    $timeopen = strtotime("$startdate +" . ($i - 1) . " days $openhour:00:00");
    $timeclose = $timeopen + $durationseconds;

    // Availability: hide activity until open time.
    // Moodle expects 'showc' to align with each condition in 'c'.
    $availability = json_encode([
        'op' => '&',
        'showc' => [false],
        'c' => [
            ['type' => 'date', 'd' => '>=', 't' => $timeopen],
        ],
    ]);

    // Prepare module data for add_moduleinfo
    $moduleinfo = new stdClass();
    $moduleinfo->modname = 'quiz';
    // Some Moodle APIs expect 'modulename' property.
    $moduleinfo->modulename = 'quiz';
    $moduleinfo->module = $DB->get_field('modules', 'id', ['name' => 'quiz']);
    $moduleinfo->course = $courseid;
    $moduleinfo->section = 0;
    $moduleinfo->name = $quizname;
    // Default visibility: show the activity link; timeopen controls attempts.
    $moduleinfo->visible = 1;
    $moduleinfo->visibleold = 1;
    $moduleinfo->intro = "Daily Network Security Challenge - $quizname";
    $moduleinfo->introformat = FORMAT_HTML;
    // Quiz-specific defaults
    $moduleinfo->password = '';
    $moduleinfo->quizpassword = '';
    $moduleinfo->timeopen = $timeopen;
    $moduleinfo->timeclose = $timeclose;
    $moduleinfo->timelimit = $timelimitseconds;
    $moduleinfo->attempts = $attemptsallowed;
    $moduleinfo->availability = $availability;
    // Question behaviour and shuffle.
    $moduleinfo->preferredbehaviour = 'adaptive';
    $moduleinfo->shuffleanswers = 1;
    // navmethod: 1 = sequential, 0 = free (depending on Moodle version)
    $moduleinfo->navmethod = 1;
    // review options: disable showing correct/grades during/after attempts
    $moduleinfo->reviewattempt = 0;
    $moduleinfo->reviewcorrectness = 0;
    $moduleinfo->reviewmarks = 0;
    $moduleinfo->reviewspecificfeedback = 0;
    $moduleinfo->reviewgeneralfeedback = 0;
    $moduleinfo->reviewrightanswer = 0;
    $moduleinfo->reviewoverallfeedback = 0;

    // Rely on module-level timeopen/timeclose fields instead of building
    // a custom availability tree to avoid version-specific JSON structures.
    // $moduleinfo->availability is intentionally left unset.

    // Create the module in the course using add_moduleinfo
    try {
        // Avoid creating duplicate quizzes: if a quiz with this name already exists in the course,
        // reuse it and get its course module id.
        $quizmoduleid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
        $existingquiz = $DB->get_record('quiz', ['course' => $courseid, 'name' => $quizname]);
        if ($existingquiz) {
            $quizinstanceid = $existingquiz->id;
            $cmid = $DB->get_field('course_modules', 'id', ['instance' => $quizinstanceid, 'module' => $quizmoduleid]);
            $cm = new stdClass();
            $cm->instance = $quizinstanceid;
            $cm->id = $cmid;
            // Ensure the activity becomes visible automatically at open time via availability rules
            if ($cmid) {
                $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
                $DB->set_field('course_modules', 'visible', 1, ['id' => $cmid]);
            }
            // Update scheduling for existing quiz to new computed times.
            $existingquiz->timeopen = $timeopen;
            $existingquiz->timeclose = $timeclose;
            $existingquiz->timelimit = $timelimitseconds;
            $existingquiz->attempts = $attemptsallowed;
            // Ensure grade field exists to avoid mod_form warnings.
            if (!isset($existingquiz->grade)) {
                $existingquiz->grade = 0;
            }
            $DB->update_record('quiz', $existingquiz);
            echo "Quiz already exists: $quizname (quizid={$quizinstanceid}, cmid={$cmid}) — scheduling updated to timeopen=" . date('c', $timeopen) . ", timeclose=" . date('c', $timeclose) . "\n";
        } else {
            $cm = add_moduleinfo($moduleinfo, $course);
        }
        // add_moduleinfo returns the module info including instance id
        if (empty($cm->instance)) {
            echo "Failed to create quiz '$quizname' (no instance returned)\n";
            continue;
        }
        $quizinstanceid = $cm->instance;
        echo "Created quiz: $quizname (quizid={$quizinstanceid}, cmid={$cm->id})\n";

        // Try to find question by name imported from GIFT. We expect question names like 'Day 01 - Beginner - Firewall Purpose'
        // We'll search in question 'name' field within categories belonging to this course or system category.
        $qsearchname = null;
        // Build expected question name by looking for question records starting with 'Day XX'
        // We query question table for names LIKE 'Day 01%'
        // Simpler lookup: avoid joining question_categories (schema changes across versions).
        $qrec = $DB->get_record_sql("SELECT * FROM {question} WHERE name LIKE ? ORDER BY id LIMIT 1", [ $question_title_prefix . $day . '%' ]);
        if ($qrec) {
            $questionid = $qrec->id;
            echo "Found question id=$questionid for '$quizname' (question name='{$qrec->name}'). Attaching to quiz...\n";

            // Add question to quiz using the proper API so references/slots are created correctly.
            try {
                $quizobj = $DB->get_record('quiz', ['id' => $quizinstanceid], '*', MUST_EXIST);
                // Ensure course property exists for quiz_add_quiz_question compatibility.
                if (!isset($quizobj->course)) {
                    $quizobj->course = $courseid;
                }
                quiz_add_quiz_question($questionid, $quizobj, 0, 1.0);
                echo "Attached question $questionid to quiz $quizinstanceid\n";
            } catch (Exception $e) {
                echo "Exception while attaching question $questionid to quiz $quizinstanceid: " . get_class($e) . ": " . $e->getMessage() . "\n";
                if ($e instanceof dml_write_exception) {
                    echo "DB write error: " . $e->error . "\n";
                } elseif ($e instanceof dml_read_exception) {
                    echo "DB read error: " . $e->error . "\n";
                }
                echo "Trace:\n" . $e->getTraceAsString() . "\n";
            }

            // Set activity completion for this quiz: require submission
            $completion = new stdClass();
            $completion->cmid = $cm->id;
            $completion->course = $courseid;
            $completion->userid = 0;
            // Configure completion: require view or require grade? We'll set 'requiregrade' = 0 and 'requirepass' = 0, 'requireview'=0, but quiz completion uses course_modules_completion
            // Simpler approach: set completion expected setting in course_modules table via module info
            // Use course_add_cm_completion? There's API but to keep simple, we won't modify completion DB directly here. Admin can set through UI if required.

        } else {
            echo "Question for '$quizname' not found in question bank. Please import GIFT first or check question names.\n";
        }

    } catch (Exception $e) {
        echo "Exception while creating quiz '$quizname': " . get_class($e) . ': ' . $e->getMessage() . "\n";
        if ($e instanceof dml_write_exception) {
            echo "DB write error: " . $e->error . "\n";
            echo "SQL: " . $e->sql . "\n";
            echo "Params: " . var_export($e->params, true) . "\n";
        } else if ($e instanceof dml_read_exception) {
            echo "DB read error: " . $e->error . "\n";
            echo "SQL: " . $e->sql . "\n";
            echo "Params: " . var_export($e->params, true) . "\n";
        }
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
    }
}

echo "Done. Review created quizzes in course '{$course->fullname}' (id={$courseid}).\n";
echo "Notes:\n";
echo " - This script attempts to attach the first question whose name starts with 'Day XX'. If your question names differ, adjust the search logic.\n";
echo " - It sets availability JSON so the quiz is not available until the open date.\n";
echo " - It disables review flags so answers/grades are not shown.\n";
echo " - Activity completion locking (require completion of previous quiz) is not fully configured automatically here. You can set 'Restrict access' -> 'Activity completion' in the UI, or I can extend the script to programmatically add the restrictions if you want.\n";

exit(0);
 
