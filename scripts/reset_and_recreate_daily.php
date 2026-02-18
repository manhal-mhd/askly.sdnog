<?php
// reset_and_recreate_daily.php
// Backup then remove existing 'Day %' quizzes and questions, import GIFT, recreate quizzes.
if (php_sapi_name() !== 'cli') {
    echo "CLI only\n";
    exit(1);
}
define('CLI_SCRIPT', true);
require_once('/var/www/moodle/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/gift/format.php');
require_once($CFG->libdir . '/questionlib.php');

global $DB, $CFG;

$courseid = 2;
$giftpath = '/var/www/moodle/local/scripts/Daily_Network_Security_Challenge.gift';

echo "Starting reset_and_recreate_daily for course id={$courseid}\n";

// 1) Backup relevant tables into new backup tables
echo "Backing up existing Day quizzes and questions...\n";
$pattern = 'Day %';
try {
    $DB->execute("CREATE TABLE IF NOT EXISTS {backup_day_quizzes} AS SELECT * FROM {quiz} WHERE course = ? AND name LIKE ?", [$courseid, $pattern]);
} catch (Exception $e) {
    // Some DB drivers don't support CREATE TABLE ... AS; fallback to copying rows individually
    $rows = $DB->get_records_sql("SELECT * FROM {quiz} WHERE course = ? AND name LIKE ?", [$courseid, $pattern]);
    if ($rows) {
        foreach ($rows as $r) {
            $DB->insert_record('backup_day_quizzes', $r, false);
        }
    }
}

// 2) Find and delete the course modules for those quizzes (use course_delete_module)
echo "Deleting Day quiz modules...\n";
$quizzes = $DB->get_records_sql("SELECT id, name FROM {quiz} WHERE course = ? AND name LIKE ?", [$courseid, $pattern]);
foreach ($quizzes as $q) {
    $cm = get_coursemodule_from_instance('quiz', $q->id, $courseid, false, MUST_EXIST);
    echo "Deleting module cmid={$cm->id} for quiz id={$q->id} name={$q->name}\n";
    try {
        course_delete_module($cm->id);
    } catch (Exception $e) {
        echo "Failed to delete cmid={$cm->id}: " . $e->getMessage() . "\n";
    }
}

// 3) Backup and delete questions named 'Day %' from question bank
echo "Backing up and deleting question bank entries named 'Day %'...\n";
$qrows = $DB->get_records_sql("SELECT * FROM {question} WHERE name LIKE ?", [$pattern]);
if ($qrows) {
    // Create backup table if not exists
    try {
        $DB->execute("CREATE TABLE IF NOT EXISTS {backup_day_questions} AS SELECT * FROM {question} WHERE name LIKE ?", [$pattern]);
    } catch (Exception $e) {
        // ignore
    }
    foreach ($qrows as $qr) {
        echo "Backing up question id={$qr->id} name={$qr->name}\n";
        try {
            // insert into backup table if exists
            $DB->insert_record('backup_day_questions', $qr, false);
        } catch (Exception $e) {
            // ignore
        }
        // Delete via API to keep DB integrity
        try {
            question_delete_question($qr->id);
            echo "Deleted question id={$qr->id}\n";
        } catch (Exception $e) {
            echo "Could not delete question id={$qr->id}: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "No Day questions found to delete.\n";
}

// 4) Create a temporary hidden quiz module to host a module-level question category (import requires CONTEXT_MODULE)
echo "Creating temporary quiz module to host import category...\n";
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$coursectx = context_course::instance($courseid);
$tempmod = new stdClass();
$tempmod->modname = 'quiz';
$tempmod->modulename = 'quiz';
// Set the module id explicitly to avoid DB write errors.
$quizmodule = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
$tempmod->module = $quizmodule->id;
$tempmod->course = $courseid;
$tempmod->section = 0;
$tempmod->name = 'Daily import holder (temporary)';
$tempmod->visible = 0;
$tempmod->visibleold = 0;
$tempmod->intro = 'Temporary module to import questions into module context';
$tempmod->introformat = FORMAT_HTML;
// minimal quiz fields
$tempmod->password = '';
$tempmod->quizpassword = '';
// Safe defaults for required quiz config to satisfy add_instance.
$tempmod->timeopen = 0;
$tempmod->timeclose = 0;
$tempmod->timelimit = 0;
$tempmod->preferredbehaviour = 'deferredfeedback';
$tempmod->attempts = 0;
$tempmod->navmethod = 'free';
$tempmod->shuffleanswers = 1;
$tempmod->grade = 0;
$tempmod->sumgrades = 0;
$tempmod->decimalpoints = 2;
$tempmod->questiondecimalpoints = 2;
$tempcm = add_moduleinfo($tempmod, $course);
if (empty($tempcm->id)) {
    echo "Failed to create temporary module for import. Aborting.\n";
    exit(1);
}
$tempcmid = $tempcm->coursemodule;
echo "Created temporary module cmid={$tempcmid}\n";

// Create question category with module context
// Refresh course caches to ensure the new context exists.
rebuild_course_cache($courseid, true);
try {
    $modulecontext = context_module::instance($tempcmid);
} catch (Exception $e) {
    echo "Failed to get module context for cmid={$tempcmid}: " . $e->getMessage() . "\n";
    exit(1);
}
$cat = $DB->get_record('question_categories', ['contextid' => $modulecontext->id], '*', IGNORE_MISSING);
if (!$cat) {
    $cat = new stdClass();
    $cat->contextid = $modulecontext->id;
    $cat->name = 'Daily Network Security Challenge';
    $cat->info = '';
    $cat->infoformat = FORMAT_MOODLE;
    $cat->parent = 0;
    $cat->sortorder = 0;
    $cat->idnumber = '';
    $cat->hidden = 0;
    $cat->stamp = '';
    $cat->timecreated = time();
    $cat->timemodified = time();
    $newcatid = $DB->insert_record('question_categories', $cat);
    $cat->id = $newcatid;
    echo "Created question category id={$cat->id} in module context\n";
} else {
    echo "Using existing module category id={$cat->id}\n";
}

// 5) Import GIFT file into that category
if (!file_exists($giftpath)) {
    echo "GIFT file not found at {$giftpath}. Aborting import.\n";
} else {
    echo "Importing GIFT file into category id={$cat->id}...\n";
    $qformat = new qformat_gift();
    $qformat->setCategory($cat);
    // Use module context for import to satisfy CONTEXT_MODULE requirement.
    $qformat->setContexts([context_module::instance($tempcmid)]);
    $qformat->setCourse($course);
    $qformat->setFilename($giftpath);
    $qformat->setRealfilename(basename($giftpath));
    $qformat->setMatchgrades('error');
    $qformat->setCatfromfile(false);
    $qformat->setContextfromfile(false);
    $qformat->setStoponerror(true);
    if (!$qformat->importpreprocess()) {
        echo "importpreprocess failed\n";
    } else {
        if (!$qformat->importprocess()) {
            echo "importprocess failed (check output)\n";
        } else {
            $qformat->importpostprocess();
            echo "Import complete.\n";
        }
    }
}

// 6) Move imported questions to course context category, then delete temporary module
$targetcatname = 'Daily Network Security Challenge';
$coursecat = $DB->get_record('question_categories', ['contextid' => $coursectx->id, 'name' => $targetcatname]);
if (!$coursecat) {
    $coursecat = (object) [
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
    $coursecat->id = $DB->insert_record('question_categories', $coursecat);
    echo "Created course-level question category id={$coursecat->id}.\n";
}
// Move all questions from module category to course category.
$sql = "SELECT q.id
          FROM {question} q
          JOIN {question_versions} qv ON qv.questionid = q.id
          JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
         WHERE qbe.questioncategoryid = ?";
$qids = $DB->get_records_sql_menu($sql, [$cat->id]);
if ($qids) {
    $ids = array_keys($qids);
    echo "Moving " . count($ids) . " imported questions to course category id={$coursecat->id}...\n";
    question_move_questions_to_category($ids, $coursecat->id);
}

// Delete the temporary module so it does not remain visible.
echo "Deleting temporary module cmid={$tempcmid}...\n";
course_delete_module($tempcmid);
echo "Temporary module removed.\n";

// 7) Re-run quiz creation script
echo "Re-running make_daily_quizzes.php to recreate quizzes and attach imported questions...\n";
passthru("php /var/www/moodle/local/scripts/make_daily_quizzes.php", $exitcode);
echo "make_daily_quizzes.php exit code: {$exitcode}\n";

echo "Done.\n";
exit(0);
