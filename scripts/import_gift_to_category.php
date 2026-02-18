<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to import a GIFT file into a course question bank category.
 *
 * Usage:
 *   sudo -u www-data php local/scripts/import_gift_to_category.php \
 *     --courseid=3 \
 *     --file=/var/www/moodle/local/scripts/Daily_Network_Security_Challenge.gift \
 *     --category="Daily Network Security Challenge"
 */

define('CLI_SCRIPT', 1);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/gift/format.php');

use core\session\manager as session_manager;
use core\context;
use core_question\category_manager;

list($options, $unrecognized) = cli_get_params([
    'courseid' => null,
    'file' => null,
    'category' => null,
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

if ($options['help'] || empty($options['courseid']) || empty($options['file']) || empty($options['category'])) {
    $help = <<<EOL
Import questions from a GIFT file into a course question bank category.

Options:
    --courseid=INT     Target course ID.
    --file=PATH        Path to GIFT file to import.
    --category=STRING  Category name to import into (created if missing).
-s, --showdebugging   Show developer level debugging information.
-h, --help            Print out this help.

Example:
$sudo -u www-data php local/scripts/import_gift_to_category.php \
    --courseid=3 \
    --file=/var/www/moodle/local/scripts/Daily_Network_Security_Challenge.gift \
    --category="Daily Network Security Challenge"
EOL;

    echo $help . "\n";
    exit(0);
}

if ($options['showdebugging']) {
    set_debugging(DEBUG_DEVELOPER, true);
}

// Run as admin for necessary capabilities.
$admin = get_admin();
if (!$admin) {
    throw new moodle_exception('noadmins');
}
session_manager::set_user($admin);
cli_heading("Running as admin user id={$admin->id}");

$courseid = (int)$options['courseid'];
$filepath = $options['file'];
$categoryname = trim($options['category']);

if (!is_readable($filepath)) {
    cli_error("File not readable: {$filepath}");
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
cli_heading("Importing into course id={$course->id} ({$course->fullname})");

// Get the default Question bank activity instance in this course.
$qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
if (!$qbank) {
    cli_error('Unable to get default Question bank instance for this course.');
}
$modcontext = \context_module::instance($qbank->id);

// Ensure target category exists under the course Question bank context.
$topcat = question_get_top_category($modcontext->id, true);
$existing = $DB->get_record('question_categories', [
    'name' => $categoryname,
    'contextid' => $modcontext->id,
    'parent' => $topcat->id,
]);
if ($existing) {
    $targetcategory = $existing;
    cli_heading("Category exists: '{$categoryname}' (id={$existing->id})");
} else {
    $cmgr = new category_manager();
    $parent = $topcat->id . ',' . $modcontext->id;
    $newcatid = $cmgr->add_category($parent, $categoryname, '', FORMAT_HTML, null);
    $targetcategory = $DB->get_record('question_categories', ['id' => $newcatid], '*', MUST_EXIST);
    cli_heading("Created category: '{$categoryname}' (id={$newcatid})");
}

// Prepare GIFT importer.
$format = new qformat_gift();
// Provide course and contexts to importer for category path handling.
$format->setCourse($course);
$format->setContexts([$modcontext]);
$format->setCategory($targetcategory);
$format->setFilename($filepath);
$format->setRealfilename($filepath);
$format->setMatchgrades('error');
$format->setCatfromfile(false);
$format->setContextfromfile(false);
$format->setStoponerror(true);
$format->set_display_progress(true);

// Run import.
if (!$format->importpreprocess()) {
    cli_error('Import preprocess failed.');
}

$ok = $format->importprocess();
if (!$ok) {
    cli_error('Import process failed.');
}

if (!$format->importpostprocess()) {
    cli_error('Import postprocess failed.');
}

$count = is_array($format->questionids) ? count($format->questionids) : 0;
cli_heading("Imported {$count} questions into category '{$categoryname}' (id={$targetcategory->id}).");
echo "Done.\n";
