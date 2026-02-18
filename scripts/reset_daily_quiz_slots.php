<?php
// Reset each Day NN quiz to have exactly 1 random question from its Day subcategory.

define('CLI_SCRIPT', 1);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/classes/quiz_settings.php');
require_once($CFG->libdir . '/questionlib.php');

use core\session\manager as session_manager;

list($options, $unrecognized) = cli_get_params([
    'courseid' => null,
    'parentcategory' => 'Daily Network Security Challenge',
    'days' => 30,
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
Reset Day 01..Day NN quizzes to 1 random question from their Day subcategory.

Options:
    --courseid=INT              Target course ID.
    --parentcategory=STRING     Parent category name (default: Daily Network Security Challenge).
    --days=INT                  Number of days/quizzes (default: 30).
-s, --showdebugging            Show developer-level debugging info.
-h, --help                     Print help.

Example:
$sudo -u www-data php local/scripts/reset_daily_quiz_slots.php --courseid=3 \
    --parentcategory="Daily Network Security Challenge" --days=30
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

// Resolve question bank module context and parent/day categories.
$qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
if (!$qbank) {
    cli_error('Unable to get default Question bank instance for this course.');
}
$modcontext = \context_module::instance($qbank->id);
$topcat = question_get_top_category($modcontext->id, true);
$parentname = trim($options['parentcategory']);
$parent = $DB->get_record('question_categories', [
    'contextid' => $modcontext->id,
    'parent' => $topcat->id,
    'name' => $parentname,
], '*', MUST_EXIST);

$days = max(1, (int)$options['days']);

for ($i = 1; $i <= $days; $i++) {
    $day = sprintf('%02d', $i);
    $quizname = "Day {$day} - Daily Network Security Challenge";
    $daycategoryname = "Day {$day}";
    $daycategory = $DB->get_record('question_categories', [
        'contextid' => $modcontext->id,
        'parent' => $parent->id,
        'name' => $daycategoryname,
    ]);
    if (!$daycategory) {
        echo "Skipping {$quizname}: day subcategory '{$daycategoryname}' not found.\n";
        continue;
    }

    $quiz = $DB->get_record('quiz', ['course' => $course->id, 'name' => $quizname]);
    if (!$quiz) {
        echo "Skipping {$quizname}: quiz not found.\n";
        continue;
    }
    $quizsettings = \mod_quiz\quiz_settings::create($quiz->id);
    $structure = $quizsettings->get_structure();
    $slots = $structure->get_slots();
    // Remove all existing slots.
    foreach ($slots as $slot) {
        $structure->remove_slot($slot->slot);
    }
    // Add 1 random question from the day category.
    $filtercondition = [
        'filter' => [
            'category' => [
                'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                'values' => [ (int)$daycategory->id ],
                'filteroptions' => ['includesubcategories' => false],
            ],
        ],
    ];
    $structure->add_random_questions(0, 1, $filtercondition);
    echo "Reset {$quizname}: now has 1 random question from '{$daycategoryname}'.\n";
}

echo "Done resetting daily quizzes to single-question format.\n";