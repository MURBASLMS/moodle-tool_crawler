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
 * Audits quiz question content (question text, feedback, answers) for embedded pluginfile.php
 * references that a real enrolled student would not actually be able to load - most commonly because
 * the reference was hardcoded (pasted URL rather than file picker) pointing at a *different* course's
 * context, left over from a course rollover/import.
 *
 * This is a pure database check - it does not make any HTTP requests - so it runs in seconds/minutes
 * even across a whole site, and is far more targeted than a generic link crawl for this specific
 * problem. It also flags embedded files that no longer exist at all, and embedded files over a
 * configurable size threshold.
 *
 * Usage:
 *   php admin/tool/crawler/cli/check_question_images.php
 *   php admin/tool/crawler/cli/check_question_images.php --courseid=123
 *   php admin/tool/crawler/cli/check_question_images.php --oversizebytes=2097152 --format=csv
 *
 * @package    tool_crawler
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_crawler\local\question_image_audit;

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help'          => false,
        'courseid'      => 0,
        'oversizebytes' => question_image_audit::DEFAULT_OVERSIZE_BYTES,
        'limit'         => 0,
        'format'        => 'table',
        'verbose'       => false,
    ],
    [
        'h' => 'help',
        'v' => 'verbose',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo <<<EOT
Audits quiz question content for embedded pluginfile.php references a real
student could not actually load (wrong/old context, missing file, or
oversized file).

Options:
 --courseid=N        Restrict to questions currently used by a quiz in this course (default: all courses).
 --oversizebytes=N    Flag embedded files at or above this size in bytes (default: {$options['oversizebytes']}).
 --limit=N            Stop after N issues found (default: 0 = unlimited).
 --format=table|csv   Output format (default: table).
 -v, --verbose        Print what was actually scanned (entries, questions, quizzes matched, fields
                       scanned, pluginfile references found) before the results.
 -h, --help           Print this help.

Example:
 \$ php admin/tool/crawler/cli/check_question_images.php --courseid=1234 --verbose

EOT;
    exit(0);
}

if ($options['verbose'] && $options['courseid']) {
    cli_writeln("Restricting scan to course id {$options['courseid']} only.");
}

$issues = question_image_audit::run([
    'courseid'      => (int) $options['courseid'],
    'oversizebytes' => (int) $options['oversizebytes'],
    'limit'         => (int) $options['limit'],
], $stats);

if ($options['verbose']) {
    cli_writeln(str_repeat('=', 78));
    cli_writeln('Scan summary:');
    cli_writeln('  Question bank entries in scope: '
        . ($stats['entriesfound'] === null ? 'all (site-wide run)' : $stats['entriesfound']));
    cli_writeln('  Questions scanned (latest version of each): ' . $stats['questionsscanned']);
    cli_writeln('  Quizzes matched: ' . $stats['quizzesmatched']);
    foreach ($stats['quiznames'] as $quizname) {
        cli_writeln('    - ' . $quizname);
    }
    cli_writeln('  Question text/feedback/answer fields scanned: ' . $stats['fieldsscanned']);
    cli_writeln('  pluginfile.php/draftfile.php references found in those fields: ' . $stats['pluginfilerefsfound']);
    cli_writeln('  Issues found: ' . $stats['issuesfound']);
    cli_writeln('  Duration: ' . $stats['durationseconds'] . 's');
    cli_writeln(str_repeat('=', 78));
}

if (empty($issues)) {
    cli_writeln('No issues found.');
    exit(0);
}

if ($options['format'] === 'csv') {
    $out = fopen('php://stdout', 'w');
    fputcsv($out, [
        'questionid', 'questionname', 'qtype', 'sourcetable', 'sourcefield',
        'courses', 'quizlinks', 'embeddedcontext', 'embeddedurl', 'issuetypes', 'filesize', 'editurl',
    ]);
    foreach ($issues as $row) {
        fputcsv($out, (array) $row);
    }
    fclose($out);
} else {
    foreach ($issues as $row) {
        cli_writeln(str_repeat('-', 78));
        cli_writeln("Question #{$row->questionid} \"{$row->questionname}\" ({$row->qtype})");
        cli_writeln("  Source:    {$row->sourcetable}.{$row->sourcefield}");
        cli_writeln("  Used in:   {$row->courses}");
        cli_writeln("  Quiz:      {$row->quizlinks}");
        cli_writeln("  Embedded:  {$row->embeddedurl}");
        cli_writeln("  Embedded context is: {$row->embeddedcontext}");
        cli_writeln("  Issue(s):  {$row->issuetypes}"
            . ($row->filesize !== null ? " (filesize={$row->filesize} bytes)" : ''));
        cli_writeln("  Edit:      {$row->editurl}");
    }
    cli_writeln(str_repeat('-', 78));
    cli_writeln(count($issues) . ' issue(s) found.');
}
