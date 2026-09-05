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

namespace tool_crawler\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Audits quiz/question content for embedded pluginfile.php image (and other file) references that
 * a real student would not actually be able to load.
 *
 * Unlike the generic tool_crawler HTTP crawl, this works entirely at the database level: it compares
 * the contextid literally embedded in a pluginfile.php URL against the set of contexts the question
 * is legitimately associated with (its owning question_category context, and every context it is
 * currently referenced from via question_references, e.g. a quiz's course-module context).
 *
 * The most common real-world cause this is designed to catch: a question was authored (or rolled over
 * from a previous offering) with a raw pluginfile.php URL pasted into the HTML source of the question
 * text/feedback/answer, instead of using the file picker. That URL bakes in the *old* course's
 * contextid. Moodle's file serving checks the contextid actually present in the URL, so a student
 * enrolled only in the new offering has no access to that old context and gets a permission failure,
 * even though staff who happen to also have access to (or broader capabilities across) the old course
 * don't notice anything wrong.
 *
 * It also flags any embedded file that no longer exists at all (genuinely broken for everyone), and
 * any embedded file over a configurable size threshold (oversized images).
 *
 * @package    tool_crawler
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_image_audit {

    /** @var int Default "oversized" threshold in bytes (1 MB). */
    const DEFAULT_OVERSIZE_BYTES = 1048576;

    /**
     * Content sources to scan for embedded pluginfile.php references.
     *
     * Each entry describes a table/fields combination to scan. This deliberately covers the highest
     * traffic question types first (multichoice, truefalse, shortanswer, essay, match, numerical, all
     * of which use the core `question` and `question_answers` tables) rather than attempting to be
     * exhaustive for every third-party question type out of the box. Add more entries here (e.g. for
     * ddwtos, gapselect, coderunner, ...) if you use those types and want them covered too.
     *
     * Each source must produce rows with at least: questionid, and one or more text fields.
     *
     * @return array
     */
    protected static function get_content_sources() {
        return [
            // Core question fields.
            [
                'table' => 'question',
                'idfield' => 'id',
                'fields' => ['questiontext', 'generalfeedback'],
            ],
            // Multi-try hints (used by interactive/adaptive behaviours across most qtypes).
            [
                'table' => 'question_hints',
                'idfield' => 'questionid',
                'fields' => ['hint'],
            ],
            // Answers and per-answer feedback (multichoice, truefalse, shortanswer, numerical, ...).
            [
                'table' => 'question_answers',
                'idfield' => 'question',
                'fields' => ['answer', 'feedback'],
            ],
            // Multichoice whole-question feedback.
            [
                'table' => 'qtype_multichoice_options',
                'idfield' => 'questionid',
                'fields' => ['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'],
            ],
            // Match whole-question feedback and subquestion text.
            [
                'table' => 'qtype_match_options',
                'idfield' => 'questionid',
                'fields' => ['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'],
            ],
            [
                'table' => 'qtype_match_subquestions',
                'idfield' => 'questionid',
                'fields' => ['questiontext'],
            ],
            // Essay grader info / response template (visible to graders/students respectively).
            [
                'table' => 'qtype_essay_options',
                'idfield' => 'questionid',
                'fields' => ['graderinfo', 'responsetemplate'],
            ],
        ];
    }

    /**
     * Matches pluginfile.php URLs of the form pluginfile.php/<contextid>/<component>/<filearea>/<itemid>/<path>.
     */
    const PLUGINFILE_REGEX =
        '#pluginfile\.php/(?<contextid>\d+)/(?<component>[a-zA-Z0-9_]+)/(?<filearea>[a-zA-Z0-9_]+)/(?<itemid>\d+)/(?<path>[^"\'\)\s<>]*)#';

    /**
     * Runs the audit.
     *
     * @param array $options {
     *     @type int      $courseid       Restrict to questions currently used within this course (0 = all courses).
     *     @type int      $oversizebytes  Flag files at or above this size. Defaults to self::DEFAULT_OVERSIZE_BYTES.
     *     @type int      $limit          Maximum number of issue rows to return (0 = unlimited).
     * }
     * @return array Array of stdClass issue rows, see build_issue_row().
     */
    public static function run(array $options = []) {
        $courseid      = $options['courseid'] ?? 0;
        $oversizebytes = $options['oversizebytes'] ?? self::DEFAULT_OVERSIZE_BYTES;
        $limit         = $options['limit'] ?? 0;

        // Map question.id => question_bank_entries.id, and question.id => qtype/name/course context info,
        // restricted to the latest version of each entry (we don't want to double report every historical
        // version of a question, only what is/was actually deliverable).
        $questionmap = self::get_question_map();

        if (empty($questionmap)) {
            return [];
        }

        // For every question_bank_entries.id: the owning context (question_categories.contextid), and
        // every "using" context it is currently referenced from (e.g. a quiz's course-module context).
        $validcontexts = self::get_valid_contexts_by_entry(array_column($questionmap, 'questionbankentryid'));

        // Where is each question_bank_entries.id actually used right now? (for reporting + optional
        // --courseid filtering).
        $usages = self::get_usages_by_entry(array_column($questionmap, 'questionbankentryid'));

        $issues = [];

        foreach (self::get_content_sources() as $source) {
            foreach (self::scan_source($source, $questionmap) as $ref) {
                $questionid = $ref->questionid;
                if (!isset($questionmap[$questionid])) {
                    continue;
                }
                $q   = $questionmap[$questionid];
                $qbe = $q->questionbankentryid;

                $questionusages = $usages[$qbe] ?? [];
                if ($courseid && !self::usages_include_course($questionusages, $courseid)) {
                    continue;
                }

                foreach (self::find_pluginfile_refs($ref->text) as $match) {
                    $embeddedcontextid = (int) $match['contextid'];
                    $valid = $validcontexts[$qbe] ?? [];

                    $foreigncontext = !in_array($embeddedcontextid, $valid, true);

                    $filerecord = self::find_file_record($match);
                    $missing    = ($filerecord === false);
                    $oversized  = ($filerecord && $filerecord->filesize >= $oversizebytes);

                    if (!$foreigncontext && !$missing && !$oversized) {
                        continue; // Nothing wrong with this reference.
                    }

                    $issues[] = self::build_issue_row(
                        $q,
                        $questionusages,
                        $source,
                        $ref,
                        $match,
                        $embeddedcontextid,
                        $foreigncontext,
                        $missing,
                        $oversized,
                        $filerecord
                    );

                    if ($limit && count($issues) >= $limit) {
                        return $issues;
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Returns questionid => {questionbankentryid, name, qtype} for the latest version of every
     * non-deleted question bank entry.
     *
     * @return array
     */
    protected static function get_question_map() {
        global $DB;

        // Use the highest version number per entry as "the current one".
        $sql = "SELECT q.id, q.name, q.qtype, qv.questionbankentryid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN (
                        SELECT questionbankentryid, MAX(version) AS maxversion
                          FROM {question_versions}
                      GROUP BY questionbankentryid
                       ) latest ON latest.questionbankentryid = qv.questionbankentryid
                                AND latest.maxversion = qv.version";

        $questionmap = [];
        $rs = $DB->get_recordset_sql($sql);
        foreach ($rs as $row) {
            $questionmap[$row->id] = $row;
        }
        $rs->close();

        return $questionmap;
    }

    /**
     * Returns questionbankentryid => [contextid, ...] of every context the question is legitimately
     * associated with: its owning category context, plus every context it is currently used from.
     *
     * @param array $entryids
     * @return array
     */
    protected static function get_valid_contexts_by_entry(array $entryids) {
        global $DB;

        $entryids = array_unique(array_filter($entryids));
        if (empty($entryids)) {
            return [];
        }

        $valid = [];

        [$insql, $params] = $DB->get_in_or_equal($entryids);

        // Owning category context.
        $sql = "SELECT qbe.id AS entryid, qc.contextid
                  FROM {question_bank_entries} qbe
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qbe.id $insql";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $valid[$row->entryid][] = (int) $row->contextid;
        }
        $rs->close();

        // Every context currently using this question (e.g. quiz course-module contexts).
        $sql = "SELECT qbe.id AS entryid, qr.usingcontextid
                  FROM {question_bank_entries} qbe
                  JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                 WHERE qbe.id $insql";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $valid[$row->entryid][] = (int) $row->usingcontextid;
        }
        $rs->close();

        foreach ($valid as $entryid => $contexts) {
            $valid[$entryid] = array_values(array_unique($contexts));
        }

        return $valid;
    }

    /**
     * Returns questionbankentryid => [ {courseid, coursefullname, cmid, quizname}, ... ] describing every
     * quiz currently using this question, for reporting purposes.
     *
     * @param array $entryids
     * @return array
     */
    protected static function get_usages_by_entry(array $entryids) {
        global $DB;

        $entryids = array_unique(array_filter($entryids));
        if (empty($entryids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($entryids);

        $sql = "SELECT qr.id AS refid, qr.questionbankentryid, qr.usingcontextid,
                       cm.id AS cmid, c.id AS courseid, c.fullname AS coursefullname, q.name AS quizname
                  FROM {question_references} qr
                  JOIN {context} ctx ON ctx.id = qr.usingcontextid AND ctx.contextlevel = " . CONTEXT_MODULE . "
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {course} c ON c.id = cm.course
                 WHERE qr.questionbankentryid $insql";

        $usages = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $usages[$row->questionbankentryid][] = $row;
        }
        $rs->close();

        return $usages;
    }

    /**
     * @param array $questionusages
     * @param int   $courseid
     * @return bool
     */
    protected static function usages_include_course(array $questionusages, $courseid) {
        foreach ($questionusages as $usage) {
            if ((int) $usage->courseid === (int) $courseid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scans one content source table for text fields, joined against the question map so we only fetch
     * rows for questions we actually care about.
     *
     * @param array $source
     * @param array $questionmap
     * @return \Generator yields stdClass {questionid, table, field, text}
     */
    protected static function scan_source(array $source, array $questionmap) {
        global $DB;

        $questionids = array_keys($questionmap);
        if (empty($questionids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($questionids);
        $fieldlist = implode(', ', $source['fields']);
        $sql = "SELECT id, {$source['idfield']} AS questionid, $fieldlist
                  FROM {{$source['table']}}
                 WHERE {$source['idfield']} $insql";

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            foreach ($source['fields'] as $field) {
                if (empty($row->$field)) {
                    continue;
                }
                $ref = new \stdClass();
                $ref->questionid = $row->questionid;
                $ref->table = $source['table'];
                $ref->field = $field;
                $ref->text = $row->$field;
                yield $ref;
            }
        }
        $rs->close();
    }

    /**
     * @param string $text
     * @return array Array of matches, each a ['contextid' => .., 'component' => .., 'filearea' => ..,
     *               'itemid' => .., 'path' => ..].
     */
    protected static function find_pluginfile_refs($text) {
        if (!preg_match_all(self::PLUGINFILE_REGEX, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $refs = [];
        foreach ($matches as $m) {
            $refs[] = [
                'contextid' => $m['contextid'],
                'component' => $m['component'],
                'filearea'  => $m['filearea'],
                'itemid'    => $m['itemid'],
                'path'      => rawurldecode($m['path']),
            ];
        }
        return $refs;
    }

    /**
     * Looks up the mdl_files record for a matched pluginfile reference.
     *
     * @param array $match
     * @return \stdClass|false
     */
    protected static function find_file_record(array $match) {
        global $DB;

        $path = trim($match['path'], '/');
        $parts = explode('/', $path);
        $filename = array_pop($parts);
        $filepath = '/' . (empty($parts) ? '' : implode('/', $parts) . '/');

        if ($filename === '' || $filename === null) {
            return false;
        }

        $params = [
            'contextid' => $match['contextid'],
            'component' => $match['component'],
            'filearea'  => $match['filearea'],
            'itemid'    => $match['itemid'],
            'filepath'  => $filepath,
            'filename'  => $filename,
        ];

        $record = $DB->get_record('files', $params, '*', IGNORE_MULTIPLE);

        if (!$record || $record->filename === '.') {
            return false;
        }

        return $record;
    }

    /**
     * Produces a human readable label for a context id, tolerating deleted/orphaned contexts.
     *
     * @param int $contextid
     * @return string
     */
    public static function describe_context($contextid) {
        try {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        } catch (\Exception $e) {
            $context = null;
        }

        if (!$context) {
            return "contextid={$contextid} (deleted/missing context)";
        }

        try {
            $name = $context->get_context_name(false, true);
            return "contextid={$contextid} ({$name})";
        } catch (\Exception $e) {
            return "contextid={$contextid} (orphaned: {$context->contextlevel}/{$context->instanceid})";
        }
    }

    /**
     * @param \stdClass $question
     * @param array     $questionusages
     * @param array     $source
     * @param \stdClass $ref
     * @param array     $match
     * @param int       $embeddedcontextid
     * @param bool      $foreigncontext
     * @param bool      $missing
     * @param bool      $oversized
     * @param \stdClass|false $filerecord
     * @return \stdClass
     */
    protected static function build_issue_row(
        $question,
        array $questionusages,
        array $source,
        $ref,
        array $match,
        $embeddedcontextid,
        $foreigncontext,
        $missing,
        $oversized,
        $filerecord
    ) {
        global $CFG;

        $issuetypes = [];
        if ($foreigncontext) {
            $issuetypes[] = 'foreign-context';
        }
        if ($missing) {
            $issuetypes[] = 'missing-file';
        }
        if ($oversized) {
            $issuetypes[] = 'oversized';
        }

        $courselabels = [];
        $quizlinks = [];
        foreach ($questionusages as $usage) {
            $courselabels[] = "{$usage->coursefullname} (course id {$usage->courseid})";
            $quizlinks[] = $CFG->wwwroot . "/mod/quiz/view.php?id={$usage->cmid}";
        }

        $row = new \stdClass();
        $row->questionid = $question->id;
        $row->questionname = $question->name;
        $row->qtype = $question->qtype;
        $row->sourcetable = $source['table'];
        $row->sourcefield = $ref->field;
        $row->courses = implode('; ', array_unique($courselabels)) ?: '(not currently used in any quiz)';
        $row->quizlinks = implode('; ', array_unique($quizlinks));
        $row->embeddedcontext = self::describe_context($embeddedcontextid);
        $row->embeddedurl = "pluginfile.php/{$match['contextid']}/{$match['component']}/{$match['filearea']}/"
            . "{$match['itemid']}/{$match['path']}";
        $row->issuetypes = implode(',', $issuetypes);
        $row->filesize = $filerecord ? $filerecord->filesize : null;
        $row->editurl = $CFG->wwwroot . '/question/bank/editquestion/question.php?id=' . $question->id;

        return $row;
    }
}
