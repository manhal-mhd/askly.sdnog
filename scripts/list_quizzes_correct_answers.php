<?php
// list_quizzes_correct_answers.php
// List quizzes (by pattern and course) and their questions with correct answers.
// Supports common qtypes by fetching answers with fraction > 0 from {question_answers}.
//
// Usage:
//   sudo -u www-data php /var/www/moodle/local/scripts/list_quizzes_correct_answers.php \
//     --courseids=2,3 --pattern="Day % - Daily Network Security Challenge"
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
    'courseids' => null,
    'pattern' => 'Day % - Daily Network Security Challenge',
    'limit' => 0,
    'category' => null,
], [
    'c' => 'courseids',
    'p' => 'pattern',
    'l' => 'limit',
    'y' => 'category',
]);

if ($unrecognized) {
    cli_error("Unknown options: \n  " . implode("\n  ", $unrecognized));
}

$pattern = $options['pattern'];
$limit = (int)($options['limit'] ?? 0);

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

$params = [$pattern];
$coursein = '';
if (!empty($courseids)) {
    $courseplaceholders = implode(',', array_fill(0, count($courseids), '?'));
    $coursein = "AND course IN ($courseplaceholders)";
    $params = array_merge($params, $courseids);
}

$limitclause = '';
if ($limit > 0) {
    $limitclause = " LIMIT $limit";
}

// If a category is specified, list questions directly from the question bank.
if (!empty($options['category'])) {
    $catname = trim($options['category']);
    echo "Listing questions in category '{$catname}' (including subcategories) matching pattern '{$pattern}'.\n";

    // Find all categories with the given name (across contexts) and collect their subtree ids.
    $roots = $DB->get_records('question_categories', ['name' => $catname]);
    if (!$roots) {
        echo "No question categories found with name '{$catname}'.\n";
        exit(0);
    }
    $catids = [];
    foreach ($roots as $root) {
        $catids[$root->id] = true;
        // BFS/DFS to gather children.
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
    $qparams = $inparams;
    $namesql = '';
    if (!empty($pattern)) {
        $namesql = " AND q.name LIKE ?";
        $qparams[] = $pattern;
    }

    // Moodle 5.x stores category on question bank entries. Select latest version questions per entry.
    $sql = "SELECT q.*
              FROM {question_versions} qv
              JOIN {question} q ON q.id = qv.questionid
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             WHERE qbe.questioncategoryid $insql
               AND qv.version = (SELECT MAX(v.version)
                                   FROM {question_versions} v
                                  WHERE v.questionbankentryid = qv.questionbankentryid)" . $namesql .
           " ORDER BY qbe.questioncategoryid, q.id";

    $questions = $DB->get_records_sql($sql, $qparams);
    if (!$questions) {
        echo "No questions matched in category '{$catname}'.\n";
        exit(0);
    }

    foreach ($questions as $q) {
        // Detect multichoice mode.
        $selectinfo = '';
        if ($q->qtype === 'multichoice') {
            $mc = $DB->get_record('qtype_multichoice_options', ['questionid' => $q->id]);
            if ($mc && isset($mc->single)) {
                $selectinfo = ((int)$mc->single === 1 ? 'single-select' : 'multiple-select');
            }
        }

        echo "\nQ{$q->id} [{$q->qtype}" . ($selectinfo ? ", {$selectinfo}" : '') . "] {$q->name}\n";

        $answers = $DB->get_records_sql(
            "SELECT id, answer, fraction FROM {question_answers} WHERE question = ? AND fraction > 0 ORDER BY id",
            [$q->id]
        );
        if (!$answers) {
            echo "  Correct answers: (none found)\n";
            continue;
        }

        if ($selectinfo === 'multiple-select') {
            echo "  Correct options count: " . count($answers) . "\n";
        }

        $i = 1;
        foreach ($answers as $ans) {
            $text = trim(strip_tags($ans->answer));
            echo "  Correct #$i: {$text} (fraction={$ans->fraction})\n";
            $i++;
        }
    }

    echo "\nDone.\n";
    exit(0);
}

$quizsql = "SELECT id, name, course FROM {quiz} WHERE name LIKE ? $coursein ORDER BY course, id$limitclause";
$quizzes = $DB->get_records_sql($quizsql, $params);

if (!$quizzes) {
    echo "No matching quizzes found.\n";
    exit(0);
}

foreach ($quizzes as $quiz) {
    echo "\n=== Quiz: {$quiz->name} (id={$quiz->id}) ===\n";

    $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot');
    if (!$slots) {
        echo "  No slots found in this quiz.\n";
        continue;
    }

    foreach ($slots as $slot) {
        // Resolve question via references + version.
        $ref = $DB->get_record('question_references', [
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid' => $slot->id,
        ]);

        if (!$ref) {
            echo "\n- Slot {$slot->slot}: (no question reference)\n";
            continue;
        }

        $qv = $DB->get_record('question_versions', [
            'questionbankentryid' => $ref->questionbankentryid,
            'version' => $ref->version,
        ]);

        if (!$qv) {
            // Fallback: use latest available version for this entry.
            $qv = $DB->get_record_sql(
                "SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC",
                [$ref->questionbankentryid]
            );
            if (!$qv) {
                echo "\n- Slot {$slot->slot}: (no question version)\n";
                continue;
            }
        }

        $q = $DB->get_record('question', ['id' => $qv->questionid]);
        if (!$q) {
            echo "\n- Slot {$slot->slot}: (question missing id={$qv->questionid})\n";
            continue;
        }

        // For multichoice, detect single vs multiple-select and count correct options.
        $selectinfo = '';
        if ($q->qtype === 'multichoice') {
            // Moodle 4.x stores options in qtype_multichoice_options.
            $mc = $DB->get_record('qtype_multichoice_options', ['questionid' => $q->id]);
            if ($mc && isset($mc->single)) {
                // single = 1 means single-answer; 0 means multiple answers allowed.
                $selectinfo = ((int)$mc->single === 1 ? 'single-select' : 'multiple-select');
            }
        }

        echo "\n- Slot {$slot->slot}: Q{$q->id} [{$q->qtype}" . ($selectinfo ? ", {$selectinfo}" : '') . "] {$q->name}\n";

        // Fetch correct answers from question_answers with fraction > 0.
        $answers = $DB->get_records_sql(
            "SELECT id, answer, fraction FROM {question_answers} WHERE question = ? AND fraction > 0 ORDER BY id",
            [$q->id]
        );

        if (!$answers) {
            echo "  Correct answers: (none found)\n";
            continue;
        }

        // Count correct options (useful for multiselect cases).
        $correctcount = count($answers);
        if ($selectinfo === 'multiple-select') {
            echo "  Correct options count: {$correctcount}\n";
        }

        $i = 1;
        foreach ($answers as $ans) {
            $text = trim(strip_tags($ans->answer));
            echo "  Correct #$i: {$text} (fraction={$ans->fraction})\n";
            $i++;
        }
    }
}

echo "\nDone.\n";
exit(0);
