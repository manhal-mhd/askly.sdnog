<?php
// check_quiz_slots.php - report quiz slots and question types for quizzes 2..31
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}
define('CLI_SCRIPT', true);
require_once('/var/www/moodle/config.php');
global $DB;

$quizids = range(2, 31);

// Print schema columns for debugging
try {
    $cols = $DB->get_columns('quiz_slots');
    echo "quiz_slots columns: " . implode(', ', array_keys($cols)) . "\n";
} catch (Exception $e) {
    echo "Could not read quiz_slots columns: " . $e->getMessage() . "\n";
}
try {
    $qcols = $DB->get_columns('question');
    echo "question columns: " . implode(', ', array_keys($qcols)) . "\n";
} catch (Exception $e) {
    echo "Could not read question columns: " . $e->getMessage() . "\n";
}
try {
    $qbcols = $DB->get_columns('question_bank_entries');
    echo "question_bank_entries columns: " . implode(', ', array_keys($qbcols)) . "\n";
} catch (Exception $e) {
    echo "Could not read question_bank_entries columns: " . $e->getMessage() . "\n";
}
try {
    $qvcols = $DB->get_columns('question_versions');
    echo "question_versions columns: " . implode(', ', array_keys($qvcols)) . "\n";
} catch (Exception $e) {
    echo "Could not read question_versions columns: " . $e->getMessage() . "\n";
}
foreach ($quizids as $qid) {
    try {
        // Fetch all columns for quiz_slots for inspection (some Moodle versions differ in schema)
        $rows = $DB->get_records_sql("SELECT * FROM {quiz_slots} WHERE quizid = ?", [$qid]);
        if ($rows) {
            foreach ($rows as $row) {
                echo "quiz={$qid} slot_row: " . var_export($row, true) . "\n";
            }
        } else {
            echo "quiz={$qid} has no quiz_slots rows\n";
        }
        // For each slot, show question_references -> question_bank_entries -> question link
        foreach ($rows as $row) {
            $slotid = $row->id;
            try {
                $refs = $DB->get_records('question_references', ['itemid' => $slotid, 'component' => 'mod_quiz', 'questionarea' => 'slot']);
                if ($refs) {
                    foreach ($refs as $ref) {
                        $qbe = $DB->get_record('question_bank_entries', ['id' => $ref->questionbankentryid]);
                        $q = null;
                        $qversion = null;
                        if ($qbe) {
                            // Some Moodle schemas store question id/version in question_versions table.
                            $qversion = $DB->get_record_sql('SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY id DESC LIMIT 1', [$qbe->id]);
                            if ($qversion && !empty($qversion->questionid)) {
                                $q = $DB->get_record('question', ['id' => $qversion->questionid]);
                            }
                        }
                        echo "  slot={$slotid} ref_id={$ref->id} qbe_id=" . ($qbe ? $qbe->id : '(null)') . " qbe_questionid=" . ($qversion ? ($qversion->questionid ?? '(null)') : '(no version)') . " qtype=" . ($q ? $q->qtype : '(null)') . " qname=" . ($q ? $q->name : '(missing)') . "\n";
                    }
                } else {
                    echo "  slot={$slotid} has no question_references\n";
                }
            } catch (Exception $e) {
                echo "  Error reading refs for slot={$slotid}: " . get_class($e) . ": " . $e->getMessage() . "\n";
            }
        }
    } catch (Exception $e) {
        echo "Error for quiz={$qid}: " . get_class($e) . ": " . $e->getMessage() . "\n";
        if ($e instanceof dml_read_exception) {
            echo "DB error: " . ($e->error ?? '') . "\n";
            echo "SQL: " . ($e->sql ?? '') . "\n";
            echo "Params: " . var_export($e->params ?? null, true) . "\n";
        }
    }
}

// Show question_bank_entries with NULL questionid (broken entries)
try {
    $nullqbes = $DB->get_records_sql("SELECT id, userid, timestamp FROM {question_bank_entries} WHERE questionid IS NULL ORDER BY id LIMIT 100");
    if ($nullqbes) {
        echo "\nQuestion bank entries with NULL questionid:\n";
        foreach ($nullqbes as $n) {
            echo " qbe id={$n->id} userid=" . ($n->userid ?? '(null)') . " timestamp=" . ($n->timestamp ?? '(null)') . "\n";
        }
    } else {
        echo "\nNo question_bank_entries with NULL questionid found.\n";
    }
} catch (Exception $e) {
    echo "Could not query question_bank_entries: " . $e->getMessage() . "\n";
}

exit(0);
