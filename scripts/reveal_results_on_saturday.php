<?php
// reveal_results_on_saturday.php
// CLI script to reveal quiz answers/results every Saturday for submitted daily quizzes only.
// Usage:
//   sudo -u www-data php /var/www/moodle/local/scripts/reveal_results_on_saturday.php --courseid=3
// Options:
//   --courseid=INT      Target course id (required)
//   --pattern=STRING    Quiz name LIKE pattern (default: "Day % - Daily Network Security Challenge")
//   --force             Run regardless of day of week (default: only Saturday)
//   --dry-run           Print planned changes without updating DB

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

@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once($CFG_PATH);
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/classes/question/display_options.php');
use mod_quiz\question\display_options;

list($options, $unrecognized) = cli_get_params([
    'courseids' => null, // Comma-separated list of course IDs.
    'pattern' => 'Day % - Daily Network Security Challenge',
    'force' => false,
    'dry-run' => false,
], [
    'c' => 'courseids',
    'f' => 'force',
    'n' => 'dry-run',
]);

if (!empty($unrecognized)) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unknown options:\n  $unrecognized\n");
}

$pattern = $options['pattern'];
$force = !empty($options['force']);
$dryrun = !empty($options['dry-run']);

$dow = (int)date('w'); // 0=Sunday ... 6=Saturday
if (!$force && $dow !== 6) {
    echo "Today is not Saturday (dow={$dow}); use --force to run anyway.\n";
    exit(0);
}

global $DB;

// Parse course IDs (default to 2 and 3 if not provided).
$courseids = [];
if (!empty($options['courseids'])) {
    foreach (explode(',', $options['courseids']) as $cid) {
        $cid = (int)trim($cid);
        if ($cid > 0) {
            $courseids[] = $cid;
        }
    }
}
if (empty($courseids)) {
    $courseids = [2, 3];
}

// Bitmask to reveal only after quiz is closed.
$closedmask = display_options::AFTER_CLOSE;

// Fetch target quizzes by name pattern.
foreach ($courseids as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    echo "Revealing results for course '{$course->fullname}' (id={$courseid})\n";

    $quizzes = $DB->get_records_sql(
        "SELECT id, name, timeclose FROM {quiz} WHERE course = ? AND name LIKE ? ORDER BY id",
        [$courseid, $pattern]
    );

    if (empty($quizzes)) {
        echo "No matching quizzes found with pattern '{$pattern}'.\n";
        continue;
    }

    $updated = 0;
    foreach ($quizzes as $q) {
        // Only quizzes with at least one finished attempt.
        $attempts = $DB->count_records('quiz_attempts', ['quiz' => $q->id, 'state' => 'finished']);
        if ($attempts < 1) {
            echo "Skip '{$q->name}' (quizid={$q->id}): no finished attempts yet.\n";
            continue;
        }

        $update = new stdClass();
        $update->id = $q->id;
        $update->reviewattempt = $closedmask;
        $update->reviewcorrectness = $closedmask;
        $update->reviewmaxmarks = $closedmask; // show max marks bit
        $update->reviewmarks = $closedmask;    // show achieved marks bit
        $update->reviewspecificfeedback = $closedmask;
        $update->reviewgeneralfeedback = $closedmask;
        $update->reviewrightanswer = $closedmask;
        $update->reviewoverallfeedback = $closedmask;

        if ($dryrun) {
            echo "Would reveal results for '{$q->name}' (quizid={$q->id})\n";
            continue;
        }

        $DB->update_record('quiz', $update);
        echo "Revealed results for '{$q->name}' (quizid={$q->id}) — review set to CLOSED only.\n";
        $updated++;
    }

    echo "Course {$courseid}: Updated {$updated} quizzes.\n";
}

echo "Done.\n";
exit(0);
