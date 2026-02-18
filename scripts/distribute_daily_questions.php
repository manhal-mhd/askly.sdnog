<?php
// Distribute questions into Day subcategories (5 per day) under a parent category,
// and prepare quizzes to draw 1 random question per day.

define('CLI_SCRIPT', 1);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/category_manager.php');

use core\session\manager as session_manager;

list($options, $unrecognized) = cli_get_params([
    'courseid' => null,
    'parentcategory' => 'Daily Network Security Challenge',
    'days' => 30,
    'perday' => 5,
    'showdebugging' => false,
    'help' => false,
], [
    's' => 'showdebugging',
    'h' => 'help',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help'] || empty($options['courseid'])) {
    $help = <<<EOL
Distribute questions from a parent category into Day 01..Day NN subcategories.

Options:
    --courseid=INT              Target course ID.
    --parentcategory=STRING     Parent category name (default: Daily Network Security Challenge).
    --days=INT                  Number of days (default: 30).
    --perday=INT                Questions per day (default: 5).
-s, --showdebugging            Show developer-level debugging info.
-h, --help                     Print help.

Example:
$sudo -u www-data php local/scripts/distribute_daily_questions.php --courseid=3 \
    --parentcategory="Daily Network Security Challenge" --days=30 --perday=5
EOL;
    echo $help . "\n";
    exit(0);
}

if ($options['showdebugging']) {
    set_debugging(DEBUG_DEVELOPER, true);
}

$admin = get_admin();
if (!$admin) {
    throw new moodle_exception('noadmins');
}
session_manager::set_user($admin);
cli_heading("Running as admin user id={$admin->id}");

$course = $DB->get_record('course', ['id' => (int)$options['courseid']], '*', MUST_EXIST);
cli_heading("Course: {$course->id} ({$course->fullname})");

// Get Question bank activity context for this course.
$qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
if (!$qbank) {
    cli_error('Unable to get default Question bank instance for this course.');
}
$modcontext = \context_module::instance($qbank->id);
$topcat = question_get_top_category($modcontext->id, true);

// Find parent category under top.
$parentname = trim($options['parentcategory']);
$parent = $DB->get_record('question_categories', [
    'contextid' => $modcontext->id,
    'parent' => $topcat->id,
    'name' => $parentname,
]);
if (!$parent) {
    $manager = new \core_question\category_manager();
    $newparentid = $manager->add_category($topcat->id . ',' . $modcontext->id, $parentname, '', FORMAT_HTML, null);
    $parent = $DB->get_record('question_categories', ['id' => $newparentid], '*', MUST_EXIST);
    cli_heading("Created parent category '{$parentname}' (id={$newparentid}).");
} else {
    cli_heading("Using parent category '{$parentname}' (id={$parent->id}).");
}

// Collect real question ids within parent.
$cmgr = new \core_question\category_manager();
$qids = $cmgr->get_real_question_ids_in_category($parent->id);
$total = count($qids);
cli_heading("Parent has {$total} questions.");

$days = max(1, (int)$options['days']);
$perday = max(1, (int)$options['perday']);
$maxdays = min($days, intdiv($total, $perday));
if ($maxdays < $days) {
    cli_heading("Warning: Only {$total} questions available; will distribute {$perday} per day over {$maxdays} days.");
}

// Partition and move.
$offset = 0;
for ($i = 1; $i <= $maxdays; $i++) {
    $dayname = sprintf('Day %02d', $i);
    // Create or get subcategory.
    $daycat = $DB->get_record('question_categories', [
        'contextid' => $modcontext->id,
        'parent' => $parent->id,
        'name' => $dayname,
    ]);
    if (!$daycat) {
        $newid = $cmgr->add_category($parent->id . ',' . $modcontext->id, $dayname, '', FORMAT_HTML, null);
        $daycat = $DB->get_record('question_categories', ['id' => $newid], '*', MUST_EXIST);
        echo "Created subcategory '{$dayname}' (id={$newid}).\n";
    } else {
        echo "Using existing subcategory '{$dayname}' (id={$daycat->id}).\n";
    }

    $subset = array_slice($qids, $offset, $perday);
    $offset += $perday;
    if (empty($subset)) {
        echo "No more questions to distribute for {$dayname}.\n";
        break;
    }
    $moved = question_move_questions_to_category($subset, $daycat->id);
    if ($moved) {
        echo "Moved " . count($subset) . " questions to '{$dayname}'.\n";
    } else {
        echo "Failed to move questions to '{$dayname}'.\n";
    }
}

echo "Done distributing questions into day subcategories.\n";