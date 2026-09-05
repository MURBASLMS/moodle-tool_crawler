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
 * One-off diagnostic script - NOT part of the normal tool - to chase down why a specific known-embedded
 * pluginfile.php reference isn't being found/matched by question_image_audit for a specific question.
 *
 * Usage:
 *   php admin/tool/crawler/cli/diag_pluginfile_ref.php --contextid=473598 --itemid=195588 \
 *       --filename=blobid0.png --component=question --filearea=questiontext
 *
 * Safe to run any time - read only, no side effects.
 *
 * @package    tool_crawler
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help'      => false,
        'contextid' => 0,
        'itemid'    => 0,
        'filename'  => '',
        'component' => 'question',
        'filearea'  => 'questiontext',
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error(implode("\n  ", $unrecognized));
}

if ($options['help'] || !$options['contextid'] || !$options['itemid'] || !$options['filename']) {
    echo <<<EOT
Diagnoses why a specific pluginfile.php reference isn't matching what
question_image_audit resolves as "the current question".

Required options:
 --contextid=N
 --itemid=N
 --filename=name.ext
Optional:
 --component=question (default)
 --filearea=questiontext (default)

Example:
 php admin/tool/crawler/cli/diag_pluginfile_ref.php --contextid=473598 --itemid=195588 --filename=blobid0.png

EOT;
    exit($options['help'] ? 0 : 1);
}

global $DB;

$contextid = (int) $options['contextid'];
$itemid    = (int) $options['itemid'];
$filename  = $options['filename'];
$component = $options['component'];
$filearea  = $options['filearea'];

cli_writeln("Site wwwroot: {$CFG->wwwroot}");
cli_writeln('');

cli_writeln(str_repeat('=', 78));
cli_writeln("0a. Does mdl_context row {$contextid} exist at all, and what is it?");
cli_writeln(str_repeat('=', 78));
$ctxrow = $DB->get_record('context', ['id' => $contextid]);
if ($ctxrow) {
    cli_writeln("FOUND: contextlevel={$ctxrow->contextlevel} instanceid={$ctxrow->instanceid} path={$ctxrow->path}");
} else {
    cli_writeln("NOT FOUND - contextid {$contextid} does not exist in mdl_context at all.");
}

cli_writeln('');
cli_writeln(str_repeat('=', 78));
cli_writeln("0b. ALL mdl_files rows for contextid={$contextid} + filename={$filename}, ignoring itemid/component/filearea:");
cli_writeln(str_repeat('=', 78));
$broadfiles = $DB->get_records('files', ['contextid' => $contextid, 'filename' => $filename]);
if ($broadfiles) {
    foreach ($broadfiles as $f) {
        cli_writeln("  file id={$f->id} component={$f->component} filearea={$f->filearea} itemid={$f->itemid} "
            . "filepath={$f->filepath} filesize={$f->filesize}");
    }
} else {
    cli_writeln("  NONE FOUND - no file named '{$filename}' exists anywhere under contextid={$contextid}.");
}

cli_writeln('');
cli_writeln(str_repeat('=', 78));
cli_writeln("0c. ALL mdl_files rows with filename={$filename}, ANY context (top 20):");
cli_writeln(str_repeat('=', 78));
$anyfiles = $DB->get_records('files', ['filename' => $filename], 'id DESC', '*', 0, 20);
if ($anyfiles) {
    foreach ($anyfiles as $f) {
        cli_writeln("  file id={$f->id} contextid={$f->contextid} component={$f->component} "
            . "filearea={$f->filearea} itemid={$f->itemid} filepath={$f->filepath} filesize={$f->filesize}");
    }
} else {
    cli_writeln("  NONE FOUND anywhere on this site with that filename.");
}

cli_writeln('');
cli_writeln(str_repeat('=', 78));
cli_writeln("0d. What course does course_modules.id={$ctxrow->instanceid} (from that context) actually belong to?");
cli_writeln(str_repeat('=', 78));
if ($ctxrow && $ctxrow->contextlevel == CONTEXT_MODULE) {
    $cm = $DB->get_record('course_modules', ['id' => $ctxrow->instanceid]);
    if ($cm) {
        $course = $DB->get_record('course', ['id' => $cm->course]);
        $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);
        cli_writeln("  course_modules.id={$cm->id} is a '{$modname}' in course id={$cm->course} "
            . ($course ? "(\"{$course->fullname}\")" : '(course not found)'));
    } else {
        cli_writeln('  course_modules row not found.');
    }
}

cli_writeln('');
cli_writeln(str_repeat('=', 78));
cli_writeln("0e. Does question id 1490949 (the REAL itemid found above) exist, and where is it used?");
cli_writeln(str_repeat('=', 78));
$realq = $DB->get_record('question', ['id' => 1490949]);
if ($realq) {
    cli_writeln("  FOUND: id=1490949 name=\"{$realq->name}\" qtype={$realq->qtype}");
    cli_writeln('  questiontext: ' . $realq->questiontext);
    $realqv = $DB->get_record('question_versions', ['questionid' => 1490949]);
    if ($realqv) {
        cli_writeln("  questionbankentryid={$realqv->questionbankentryid} version={$realqv->version}");
        $usagesql2 = "SELECT qr.id AS refid, qr.version AS pinnedversion, cm.id AS cmid, c.id AS courseid,
                            c.fullname AS coursefullname, quiz.name AS quizname
                       FROM {question_references} qr
                       JOIN {context} ctx ON ctx.id = qr.usingcontextid AND ctx.contextlevel = " . CONTEXT_MODULE . "
                       JOIN {course_modules} cm ON cm.id = ctx.instanceid
                       JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                       JOIN {quiz} quiz ON quiz.id = cm.instance
                       JOIN {course} c ON c.id = cm.course
                      WHERE qr.questionbankentryid = :entryid";
        $realusages = $DB->get_records_sql($usagesql2, ['entryid' => $realqv->questionbankentryid]);
        if ($realusages) {
            foreach ($realusages as $u) {
                cli_writeln("    used by quiz \"{$u->quizname}\" in course \"{$u->coursefullname}\" "
                    . "(course id {$u->courseid}), pinnedversion=" . var_export($u->pinnedversion, true));
            }
        } else {
            cli_writeln('    Not currently referenced by any quiz via question_references.');
        }
    } else {
        cli_writeln('  No question_versions row for id=1490949 (unexpected).');
    }
} else {
    cli_writeln('  NOT FOUND either.');
}

cli_writeln('');
cli_writeln(str_repeat('=', 78));
cli_writeln("1. Does the file itself exist in mdl_files?");
cli_writeln(str_repeat('=', 78));
$file = $DB->get_record('files', [
    'contextid' => $contextid,
    'component' => $component,
    'filearea'  => $filearea,
    'itemid'    => $itemid,
    'filename'  => $filename,
], '*', IGNORE_MULTIPLE);
if ($file) {
    cli_writeln("FOUND: file id={$file->id} filesize={$file->filesize} filepath={$file->filepath}");
} else {
    cli_writeln("NOT FOUND for contextid={$contextid} component={$component} filearea={$filearea} "
        . "itemid={$itemid} filename={$filename}");
}

cli_writeln('');
cli_writeln(str_repeat('=', 78));
cli_writeln("2. Does question_versions have any row for questionid={$itemid}?");
cli_writeln(str_repeat('=', 78));
$qv = $DB->get_record('question_versions', ['questionid' => $itemid]);
if ($qv) {
    cli_writeln("FOUND: questionbankentryid={$qv->questionbankentryid} version={$qv->version} status={$qv->status}");
} else {
    cli_writeln("NOT FOUND - no question_versions row has questionid={$itemid}. "
        . "Either this id never was a question id, or it belonged to a fully purged/deleted row.");
}

if ($qv) {
    cli_writeln('');
    cli_writeln(str_repeat('=', 78));
    cli_writeln("3. All versions of that same entry (questionbankentryid={$qv->questionbankentryid}):");
    cli_writeln(str_repeat('=', 78));
    $allversions = $DB->get_records(
        'question_versions',
        ['questionbankentryid' => $qv->questionbankentryid],
        'version DESC'
    );
    foreach ($allversions as $v) {
        cli_writeln("  version={$v->version} questionid={$v->questionid} status={$v->status}");
    }

    $latest = reset($allversions);
    $currentq = $DB->get_record('question', ['id' => $latest->questionid]);
    cli_writeln('');
    cli_writeln(str_repeat('=', 78));
    cli_writeln("4. Current/latest question row (id={$latest->questionid}) questiontext:");
    cli_writeln(str_repeat('=', 78));
    cli_writeln($currentq ? $currentq->questiontext : '(question row missing!)');

    cli_writeln('');
    cli_writeln(str_repeat('=', 78));
    cli_writeln("5. Owning category/context for this entry:");
    cli_writeln(str_repeat('=', 78));
    $sql = "SELECT qbe.id, qc.id AS categoryid, qc.name AS categoryname, qc.contextid
              FROM {question_bank_entries} qbe
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE qbe.id = :entryid";
    $owner = $DB->get_record_sql($sql, ['entryid' => $qv->questionbankentryid]);
    if ($owner) {
        cli_writeln("category id={$owner->categoryid} name={$owner->categoryname} contextid={$owner->contextid}"
            . ($owner->contextid == $contextid ? ' (MATCHES the embedded URL context)' : ' (DOES NOT MATCH embedded URL context '
                . $contextid . ')'));
    } else {
        cli_writeln("Could not resolve owning category for entryid={$qv->questionbankentryid}.");
    }

    cli_writeln('');
    cli_writeln(str_repeat('=', 78));
    cli_writeln("6. Which quiz(zes)/course(s) currently use this entry (via question_references)?");
    cli_writeln(str_repeat('=', 78));
    $usagesql = "SELECT qr.id AS refid, qr.version AS pinnedversion, cm.id AS cmid, c.id AS courseid,
                        c.fullname AS coursefullname, quiz.name AS quizname
                   FROM {question_references} qr
                   JOIN {context} ctx ON ctx.id = qr.usingcontextid AND ctx.contextlevel = " . CONTEXT_MODULE . "
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                   JOIN {quiz} quiz ON quiz.id = cm.instance
                   JOIN {course} c ON c.id = cm.course
                  WHERE qr.questionbankentryid = :entryid";
    $usages = $DB->get_records_sql($usagesql, ['entryid' => $qv->questionbankentryid]);
    if ($usages) {
        foreach ($usages as $u) {
            cli_writeln("  quiz \"{$u->quizname}\" in course \"{$u->coursefullname}\" (course id {$u->courseid}), "
                . 'pinnedversion=' . var_export($u->pinnedversion, true));
        }
    } else {
        cli_writeln("  Not currently referenced by any quiz.");
    }
}
