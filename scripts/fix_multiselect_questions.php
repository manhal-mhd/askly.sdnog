<?php
// fix_multiselect_questions.php
// Convert multichoice questions with multiple correct answers to multiple-select
// and rebalance fractions so the sum of correct options equals 1.0.
//
// Usage:
//   sudo -u www-data php /var/www/moodle/local/scripts/fix_multiselect_questions.php \
//     --category="Daily Network Security Challenge" [--pattern="%"] [--dry-run]
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

list($options, $unrecognized) = cli_get_params([
    'category' => null,
    'pattern' => '%',
    'dry-run' => false,
], [
    'y' => 'category',
    'p' => 'pattern',
    'n' => 'dry-run',
]);

if ($unrecognized) {
    cli_error("Unknown options: \n  " . implode("\n  ", $unrecognized));
}

$catname = trim((string)$options['category']);
if ($catname === '') {
    cli_error("--category is required");
}

$pattern = trim((string)$options['pattern']);
$dryrun = !empty($options['dry-run']);

global $DB;

echo "Scanning category '{$catname}' for multichoice questions needing multi-select fix" . ($pattern && $pattern !== '%' ? " (pattern '{$pattern}')" : '') . ($dryrun ? " [DRY-RUN]" : '') . "...\n";

// Collect all question category IDs under the named roots.
$roots = $DB->get_records('question_categories', ['name' => $catname]);
if (!$roots) {
    echo "No question categories found with name '{$catname}'.\n";
    exit(0);
}

$catids = [];
foreach ($roots as $root) {
    $catids[$root->id] = true;
    $queue = [$root->id];
    while ($queue) {
        $parentid = array_shift($queue);
        $children = $DB->get_records('question_categories', ['parent' => $parentid]);
        foreach ($children as $child) {
            if (!isset($catids[$child->id])) {
                $catids[$child->id] = true;
                $queue[] = $child->id;
            }
        }
    }
}
$catidlist = array_keys($catids);

if (empty($catidlist)) {
    echo "No subcategories found under '{$catname}'.\n";
    exit(0);
}

list($insql, $inparams) = $DB->get_in_or_equal($catidlist, SQL_PARAMS_QM);
$params = $inparams;
$namesql = '';
if ($pattern && $pattern !== '%') {
    $namesql = " AND q.name LIKE ?";
    $params[] = $pattern;
}

// Get latest-version multichoice questions in these categories.
$sql = "SELECT q.id, q.name, q.qtype, qv.version, qbe.questioncategoryid AS categoryid
          FROM {question_versions} qv
          JOIN {question} q ON q.id = qv.questionid
          JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
         WHERE qbe.questioncategoryid $insql
           AND q.qtype = 'multichoice'
           AND qv.version = (SELECT MAX(v.version)
                               FROM {question_versions} v
                              WHERE v.questionbankentryid = qv.questionbankentryid)" . $namesql .
       " ORDER BY qbe.questioncategoryid, q.id";

$questions = $DB->get_records_sql($sql, $params);
if (!$questions) {
    echo "No multichoice questions found.\n";
    exit(0);
}

$fixed = 0;
$skipped = 0;

foreach ($questions as $q) {
    // Fetch answers and options.
    $answers = $DB->get_records_sql(
        "SELECT id, answer, fraction FROM {question_answers} WHERE question = ? ORDER BY id",
        [$q->id]
    );
    if (!$answers) {
        $skipped++;
        echo "- Q{$q->id} {$q->name}: no answers, skipping\n";
        continue;
    }

    $correct = array_filter($answers, function($a) { return (float)$a->fraction > 0; });
    $ncorrect = count($correct);
    if ($ncorrect <= 1) {
        $skipped++;
        // Single correct option; nothing to change.
        continue;
    }

    $opts = $DB->get_record('qtype_multichoice_options', ['questionid' => $q->id]);
    if (!$opts) {
        $skipped++;
        echo "- Q{$q->id} {$q->name}: missing multichoice options, skipping\n";
        continue;
    }

    $needfix = ((int)$opts->single === 1) || !$dryrun;
    $targetfrac = round(1.0 / $ncorrect, 7); // 7 decimal places like Moodle typically uses.

    echo "- Q{$q->id} {$q->name}: {$ncorrect} correct options detected" . ((int)$opts->single === 1 ? ", currently single-select" : ", currently multiple-select") . ($dryrun ? " [DRY-RUN]" : "") . "\n";

    if ($dryrun) {
        // Show planned changes.
        echo "    -> set options.single = 0\n";
        echo "    -> set each correct answer fraction = {$targetfrac}\n";
        continue;
    }

    // Apply changes: set multiple-select and rebalance fractions.
    $opts->single = 0;
    $DB->update_record('qtype_multichoice_options', $opts);

    foreach ($correct as $ans) {
        $ans->fraction = $targetfrac;
        $DB->update_record('question_answers', $ans);
    }

    $fixed++;
}

echo "\nDone. Fixed {$fixed} questions; skipped {$skipped}." . ($dryrun ? " (dry-run)" : "") . "\n";
exit(0);

?>