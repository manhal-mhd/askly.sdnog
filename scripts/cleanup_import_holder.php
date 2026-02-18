<?php
// cleanup_import_holder.php
// Move imported 'Daily %' questions from the temporary module category to a course-context category,
// then delete the temporary quiz module so it does not appear in the course.
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
define('CLI_SCRIPT', true);
require_once('/var/www/moodle/config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/course/lib.php');

global $DB;
$courseid = 2;
$temponame = 'Daily import holder (temporary)';
$targetcatname = 'Daily Network Security Challenge';

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$coursectx = context_course::instance($courseid);

// Find the temporary module.
$quizmoduleid = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
// Find ALL temporary quizzes in this course.
$tempquizzes = $DB->get_records('quiz', ['course' => $courseid, 'name' => $temponame]);
if (!$tempquizzes) { echo "Temporary quizzes not found. Nothing to do.\\n"; exit(0); }

// Get or create a course-context category for the questions.
$targetcat = $DB->get_record('question_categories', ['contextid' => $coursectx->id, 'name' => $targetcatname]);
if (!$targetcat) {
    $targetcat = (object) [
        'contextid' => $coursectx->id,
        'name' => $targetcatname,
        'info' => '',
        'infoformat' => FORMAT_MOODLE,
        'parent' => 0,
        'sortorder' => 0,
        'idnumber' => '',
        'hidden' => 0,
        'stamp' => '',
        'timecreated' => time(),
        'timemodified' => time(),
    ];
    $targetcat->id = $DB->insert_record('question_categories', $targetcat);
    echo "Created target course category id={$targetcat->id}.\n";
} else {
    echo "Using existing target course category id={$targetcat->id}.\n";
}

foreach ($tempquizzes as $tempquiz) {
    $tempcm = $DB->get_record('course_modules', ['course' => $courseid, 'module' => $quizmoduleid, 'instance' => $tempquiz->id], '*', MUST_EXIST);
    $modulectx = context_module::instance($tempcm->id);

    // For each module, move questions from any categories in its context.
    $modulecats = $DB->get_records('question_categories', ['contextid' => $modulectx->id]);
    if (!$modulecats) {
        echo "No question categories found in module context cmid={$tempcm->id}.\\n";
    }
    foreach ($modulecats as $modulecat) {
        $sql = "SELECT q.id
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = ?";
        $questionids = $DB->get_records_sql_menu($sql, [$modulecat->id]);
        if ($questionids) {
            $ids = array_keys($questionids);
            echo "Moving " . count($ids) . " questions from module category id={$modulecat->id} to course category id={$targetcat->id}...\\n";
            question_move_questions_to_category($ids, $targetcat->id);
        }
    }

    // Delete the temporary module.
    try {
        echo "Deleting temporary module cmid={$tempcm->id}...\\n";
        course_delete_module($tempcm->id);
        echo "Temporary module removed.\\n";
    } catch (Exception $e) {
        echo "Failed to delete temp module cmid={$tempcm->id}: " . $e->getMessage() . "\\n";
    }
}

echo "Done.\n";
exit(0);
