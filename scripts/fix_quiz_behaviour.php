<?php
// fix_quiz_behaviour.php
// CLI: set preferredbehaviour='adaptive' for Day quizzes to fix Unknown question behaviour.
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
define('CLI_SCRIPT', true);
require_once('/var/www/moodle/config.php');

global $DB;
$courseid = 2;
$pattern = 'Day % - Daily Network Security Challenge';

$quizzes = $DB->get_records_sql("SELECT id, name, preferredbehaviour FROM {quiz} WHERE course = ? AND name LIKE ?", [$courseid, $pattern]);
if (!$quizzes) { echo "No matching Day quizzes found.\n"; exit(0); }

$validbehaviours = ['adaptive','adaptivenopenalty','deferredfeedback','deferredcbm','immediatefeedback','immediatecbm','interactive','interactivecountback'];
$count = 0;
foreach ($quizzes as $q) {
    $new = 'adaptive';
    if ($q->preferredbehaviour === $new) { echo "Quiz {$q->id} already set ({$q->name}).\n"; continue; }
    echo "Updating quiz {$q->id} ({$q->name}) behaviour '{$q->preferredbehaviour}' -> '{$new}'...\n";
    $DB->set_field('quiz', 'preferredbehaviour', $new, ['id' => $q->id]);
    $count++;
}

echo "Updated {$count} quizzes.\n";
exit(0);
