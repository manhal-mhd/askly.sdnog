<?php
// set_daily_quiz_grades.php
// Set each matching quiz's maximum grade to 100 and distribute marks equally
// across all question slots in the quiz (e.g., 1 question -> 100, 2 questions -> 50 each).
//
// Usage:
//   sudo -u www-data php /var/www/moodle/local/scripts/set_daily_quiz_grades.php --courseids=2,3 --pattern="Day % - Daily Network Security Challenge" --grade=100
//
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

if (!defined('CLI_SCRIPT')) {
    define('CLI_SCRIPT', true);
}

require_once('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/quiz/classes/quiz_settings.php');

list($options, $unrecognized) = cli_get_params([
    'courseids' => null,
    'pattern' => 'Day % - Daily Network Security Challenge',
    'grade' => 100,
    'dry-run' => false,
], [
    'c' => 'courseids',
    'g' => 'grade',
    'n' => 'dry-run',
]);

if ($unrecognized) {
    cli_error("Unknown options: \n  " . implode("\n  ", $unrecognized));
}

$pattern = $options['pattern'];
$targetgrade = (float)$options['grade'];
$dryrun = !empty($options['dry-run']);

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

global $DB;

foreach ($courseids as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid]);
    if (!$course) {
        echo "Skip course {$courseid}: not found.\n";
        continue;
    }
    echo "Processing course '{$course->fullname}' (id={$courseid})\n";

    $quizzes = $DB->get_records_sql(
        "SELECT id, name FROM {quiz} WHERE course = ? AND name LIKE ? ORDER BY id",
        [$courseid, $pattern]
    );

    if (!$quizzes) {
        echo "  No quizzes matched pattern.\n";
        continue;
    }

    foreach ($quizzes as $quiz) {
        $quizsettings = \mod_quiz\quiz_settings::create($quiz->id);
        $structure = $quizsettings->get_structure();
        $slots = $structure->get_slots();
        $slotcount = count($slots);
        if ($slotcount < 1) {
            echo "  Quiz '{$quiz->name}' (id={$quiz->id}): no slots; skipping.\n";
            continue;
        }
        // Set quiz max grade to target.
        if ($dryrun) {
            echo "  Would set quiz grade={$targetgrade} for '{$quiz->name}' (id={$quiz->id}).\n";
        } else {
            $DB->update_record('quiz', (object)['id' => $quiz->id, 'grade' => $targetgrade]);
        }

        // Distribute marks evenly per slot.
        $permark = $targetgrade / $slotcount;
        $updated = 0;
        foreach ($slots as $slot) {
            if ($dryrun) {
                echo "    Would set slot {$slot->slot} maxmark={$permark}.\n";
                $updated++;
                continue;
            }
            try {
                if ($structure->update_slot_maxmark($slot, $permark)) {
                    $updated++;
                }
            } catch (\Exception $e) {
                echo "    Failed to update slot {$slot->slot} for quiz {$quiz->id}: " . $e->getMessage() . "\n";
            }
        }

        // Recompute and persist quiz sumgrades so attempts can start.
        if ($dryrun) {
            echo "  Would recompute sumgrades for quiz id={$quiz->id}.\n";
        } else {
            try {
                $quizsettings->get_grade_calculator()->recompute_quiz_sumgrades();
            } catch (\Throwable $t) {
                echo "    Warning: could not recompute sumgrades for quiz {$quiz->id}: " . $t->getMessage() . "\n";
            }
        }

        echo "  Set grade={$targetgrade}; updated {$updated}/{$slotcount} slot marks for '{$quiz->name}'.\n";
    }
}

echo "Done.\n";
exit(0);
