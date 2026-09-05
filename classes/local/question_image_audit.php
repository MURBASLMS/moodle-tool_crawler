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
 * Unlike the generic tool_crawler HTTP crawl, this works entirely at the database level. A properly
 * authored question image (inserted via the file picker) is always saved by Moodle with a pluginfile.php
 * URL whose contextid/component/filearea/itemid are the question's *own*, current values - that is, the
 * question's owning question_categories.contextid, and (depending on field) either the question's own id
 * or the specific answer/hint row's own id. This is fixed at save time by file_save_draft_area_files()
 * and does not depend on, or vary by, which course/quiz is currently using the question - a question
 * pulled from a shared/faculty-level question bank is expected to keep referencing that bank's own
 * context regardless of which course's quiz uses it.
 *
 * So the one reliable question to ask of every embedded pluginfile.php reference is: does it still point
 * at *this exact question's own* current context/item, or does it point at something else entirely
 * (typically: a different, unrelated, "old unit" question/course - usually because a raw absolute URL
 * was pasted into the HTML source rather than inserted via the file picker/file import mechanism)? If it
 * points at something else, no amount of enrolment in the current course will make Moodle serve it,
 * because it isn't actually this question's file at all - while staff who happen to also have access to
 * that other, unrelated context won't notice anything wrong when they view it themselves.
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
     * Each entry describes a table/fields combination to scan, plus 'itemidbasis' - what the itemid in a
     * *legitimate* pluginfile.php reference for that field should equal:
     *  - 'question': the question's own id (used for the question's own text/feedback areas).
     *  - 'row':      the specific source row's own id (used for per-answer/per-hint areas).
     *  - null:       not confidently known for this field, so the itemid isn't strictly checked (only
     *                the context is), to avoid false positives.
     *
     * This deliberately covers the highest traffic question types first (multichoice, truefalse,
     * shortanswer, essay, match, numerical, all of which use the core `question` and `question_answers`
     * tables) rather than attempting to be exhaustive for every third-party question type out of the box.
     * Add more entries here (e.g. for ddwtos, gapselect, coderunner, ...) if you use those types too.
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
                'itemidbasis' => 'question',
            ],
            // Multi-try hints (used by interactive/adaptive behaviours across most qtypes).
            [
                'table' => 'question_hints',
                'idfield' => 'questionid',
                'fields' => ['hint'],
                'itemidbasis' => 'row',
            ],
            // Answers and per-answer feedback (multichoice, truefalse, shortanswer, numerical, ...).
            [
                'table' => 'question_answers',
                'idfield' => 'question',
                'fields' => ['answer', 'feedback'],
                'itemidbasis' => 'row',
            ],
            // Multichoice whole-question feedback.
            [
                'table' => 'qtype_multichoice_options',
                'idfield' => 'questionid',
                'fields' => ['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'],
                'itemidbasis' => 'question',
            ],
            // Match whole-question feedback and subquestion text.
            [
                'table' => 'qtype_match_options',
                'idfield' => 'questionid',
                'fields' => ['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'],
                'itemidbasis' => 'question',
            ],
            [
                'table' => 'qtype_match_subquestions',
                'idfield' => 'questionid',
                'fields' => ['questiontext'],
                'itemidbasis' => null,
            ],
            // Essay grader info / response template (visible to graders/students respectively).
            [
                'table' => 'qtype_essay_options',
                'idfield' => 'questionid',
                'fields' => ['graderinfo', 'responsetemplate'],
                'itemidbasis' => null,
            ],
        ];
    }

    /**
     * Matches pluginfile.php URLs of the form pluginfile.php/<contextid>/<component>/<filearea>/<itemid>/<path>.
     */
    const PLUGINFILE_REGEX =
        '#pluginfile\.php/(?<contextid>\d+)/(?<component>[a-zA-Z0-9_]+)/(?<filearea>[a-zA-Z0-9_]+)/'
        . '(?<itemid>\d+)/(?<path>[^"\'\)\s<>]*)#';

    /**
     * Runs the audit.
     *
     * @param array      $options {
     *     @type int      $courseid       Restrict to questions currently used within this course (0 = all courses).
     *     @type int      $oversizebytes  Flag files at or above this size. Defaults to self::DEFAULT_OVERSIZE_BYTES.
     *     @type int      $limit          Maximum number of issue rows to return (0 = unlimited).
     * }
     * @param array|null $stats Optional, passed by reference. Populated with scan stats for --verbose
     *                          reporting: entriesfound, questionsscanned, quizzesmatched, quiznames,
     *                          fieldsscanned, pluginfilerefsfound, issuesfound, durationseconds.
     * @return array Array of stdClass issue rows, see build_issue_row().
     */
    public static function run(array $options = [], ?array &$stats = null) {
        $starttime = microtime(true);

        $stats = [
            'entriesfound'        => 0,
            'questionsscanned'    => 0,
            'quizzesmatched'      => 0,
            'quiznames'           => [],
            'fieldsscanned'       => 0,
            'pluginfilerefsfound' => 0,
            'issuesfound'         => 0,
            'durationseconds'     => 0,
        ];

        $courseid      = $options['courseid'] ?? 0;
        $oversizebytes = $options['oversizebytes'] ?? self::DEFAULT_OVERSIZE_BYTES;
        $limit         = $options['limit'] ?? 0;

        // If restricted to a single course, narrow every subsequent query down to just the question
        // bank entries actually used by a quiz in that course, rather than scanning the whole site's
        // question bank and filtering afterwards. Cheap and safe to run against a single course in
        // production.
        $entryids = $courseid ? self::get_entryids_for_course($courseid) : null;
        if ($courseid && empty($entryids)) {
            $stats['durationseconds'] = round(microtime(true) - $starttime, 3);
            return [];
        }
        $stats['entriesfound'] = $entryids !== null ? count($entryids) : null; // null = "all" (site-wide run).

        // Map question.id => question_bank_entries.id, and question.id => qtype/name, restricted to the
        // latest version of each entry (we don't want to double report every historical version of a
        // question, only what is/was actually deliverable).
        $questionmap = self::get_question_map($entryids);
        $stats['questionsscanned'] = count($questionmap);

        if (empty($questionmap)) {
            $stats['durationseconds'] = round(microtime(true) - $starttime, 3);
            return [];
        }

        // For every question_bank_entries.id: the question's own, current owning context - the *only*
        // legitimate context for a pluginfile.php reference embedded in that question's own content,
        // regardless of which course/quiz is currently using the question.
        $owningcontexts = self::get_owning_contexts_by_entry(array_column($questionmap, 'questionbankentryid'));

        // Where is each question_bank_entries.id actually used right now? Purely for reporting (so a
        // human can see at a glance whether the owning context's course matches) + optional --courseid
        // filtering.
        $usages = self::get_usages_by_entry(array_column($questionmap, 'questionbankentryid'));

        $matchedquizzes = [];
        foreach ($usages as $qbeusages) {
            foreach ($qbeusages as $usage) {
                if (!$courseid || (int) $usage->courseid === (int) $courseid) {
                    $matchedquizzes[$usage->cmid] = $usage->quizname . ' (course id ' . $usage->courseid . ')';
                }
            }
        }
        $stats['quizzesmatched'] = count($matchedquizzes);
        $stats['quiznames'] = array_values($matchedquizzes);

        $issues = [];

        foreach (self::get_content_sources() as $source) {
            foreach (self::scan_source($source, $questionmap) as $ref) {
                $stats['fieldsscanned']++;

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

                $owningcontextid = $owningcontexts[$qbe] ?? null;
                $expecteditemid = null;
                if ($source['itemidbasis'] === 'question') {
                    $expecteditemid = $q->id;
                } else if ($source['itemidbasis'] === 'row') {
                    $expecteditemid = $ref->rowid;
                }

                foreach (self::find_pluginfile_refs($ref->text) as $match) {
                    $stats['pluginfilerefsfound']++;

                    $embeddedcontextid = (int) $match['contextid'];
                    $embeddeditemid    = (int) $match['itemid'];

                    $foreigncontext = ($owningcontextid !== null && $embeddedcontextid !== (int) $owningcontextid);
                    $wrongitem = (!$foreigncontext && $expecteditemid !== null
                        && $embeddeditemid !== (int) $expecteditemid);

                    $filerecord = self::find_file_record($match);
                    $missing    = ($filerecord === false);
                    $oversized  = ($filerecord && $filerecord->filesize >= $oversizebytes);

                    if (!$foreigncontext && !$wrongitem && !$missing && !$oversized) {
                        continue; // Nothing wrong with this reference.
                    }

                    $issues[] = self::build_issue_row(
                        $q,
                        $questionusages,
                        $source,
                        $ref,
                        $match,
                        $owningcontextid,
                        $foreigncontext,
                        $wrongitem,
                        $missing,
                        $oversized,
                        $filerecord
                    );
                    $stats['issuesfound'] = count($issues);

                    if ($limit && count($issues) >= $limit) {
                        $stats['durationseconds'] = round(microtime(true) - $starttime, 3);
                        return $issues;
                    }
                }
            }
        }

        $stats['durationseconds'] = round(microtime(true) - $starttime, 3);
        return $issues;
    }

    /**
     * Returns the question_bank_entries.id list actually used by a quiz in the given course.
     *
     * @param int $courseid
     * @return array
     */
    protected static function get_entryids_for_course($courseid) {
        global $DB;

        $sql = "SELECT DISTINCT qr.questionbankentryid
                  FROM {question_references} qr
                  JOIN {context} ctx ON ctx.id = qr.usingcontextid AND ctx.contextlevel = " . CONTEXT_MODULE . "
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE cm.course = :courseid";

        return $DB->get_fieldset_sql($sql, ['courseid' => $courseid]);
    }

    /**
     * Returns questionid => {questionbankentryid, name, qtype} for the latest version of every
     * non-deleted question bank entry.
     *
     * @param array|null $entryids If given, restrict to these question_bank_entries.id only.
     * @return array
     */
    protected static function get_question_map(?array $entryids = null) {
        global $DB;

        $params = [];
        $entryfilter = '';
        if ($entryids !== null) {
            if (empty($entryids)) {
                return [];
            }
            [$insql, $params] = $DB->get_in_or_equal($entryids);
            $entryfilter = "WHERE qv.questionbankentryid $insql";
        }

        // Use the highest version number per entry as "the current one".
        $sql = "SELECT q.id, q.name, q.qtype, qv.questionbankentryid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN (
                        SELECT questionbankentryid, MAX(version) AS maxversion
                          FROM {question_versions}
                      GROUP BY questionbankentryid
                       ) latest ON latest.questionbankentryid = qv.questionbankentryid
                                AND latest.maxversion = qv.version
                  $entryfilter";

        $questionmap = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $questionmap[$row->id] = $row;
        }
        $rs->close();

        return $questionmap;
    }

    /**
     * Returns questionbankentryid => contextid: the question's own, current owning question_categories
     * context. This is the *only* legitimate context for a pluginfile.php reference embedded in that
     * question's own content - it is fixed at save time and does not vary by which course/quiz is
     * currently using the question.
     *
     * @param array $entryids
     * @return array
     */
    protected static function get_owning_contexts_by_entry(array $entryids) {
        global $DB;

        $entryids = array_unique(array_filter($entryids));
        if (empty($entryids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($entryids);

        $sql = "SELECT qbe.id AS entryid, qc.contextid
                  FROM {question_bank_entries} qbe
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qbe.id $insql";

        $owning = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $owning[$row->entryid] = (int) $row->contextid;
        }
        $rs->close();

        return $owning;
    }

    /**
     * Returns questionbankentryid => [ {courseid, coursefullname, cmid, quizname}, ... ] describing every
     * quiz currently using this question, for reporting purposes and optional --courseid filtering.
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
     * @return \Generator yields stdClass {questionid, rowid, table, field, text}
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
                $ref->rowid = $row->id;
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
     * Produces a human readable label for a context id, tolerating deleted/orphaned contexts, and
     * explicitly identifying which course (if any) it belongs to - the key fact for answering "is this
     * the same course a student may be enrolled in".
     *
     * @param int|null $contextid
     * @return string
     */
    public static function describe_context($contextid) {
        if ($contextid === null) {
            return '(question has no resolvable owning category - question_categories row missing?)';
        }

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
        } catch (\Exception $e) {
            return "contextid={$contextid} (orphaned: level {$context->contextlevel}, instance {$context->instanceid})";
        }

        switch ($context->contextlevel) {
            case CONTEXT_COURSE:
                return "contextid={$contextid} - Course-level question bank: {$name} (course id {$context->instanceid})";
            case CONTEXT_MODULE:
                global $DB;
                $courseid = $DB->get_field('course_modules', 'course', ['id' => $context->instanceid]);
                return "contextid={$contextid} - Module-level question bank: {$name}"
                    . ($courseid ? " (in course id {$courseid})" : '');
            case CONTEXT_COURSECAT:
                return "contextid={$contextid} - Shared category-level question bank: {$name} "
                    . "(not tied to one course - expected to be usable by any course drawing from it)";
            case CONTEXT_SYSTEM:
                return "contextid={$contextid} - Site-wide shared question bank (not tied to one course)";
            default:
                return "contextid={$contextid} ({$name})";
        }
    }

    /**
     * @param \stdClass       $question
     * @param array           $questionusages
     * @param array           $source
     * @param \stdClass       $ref
     * @param array           $match
     * @param int|null        $owningcontextid
     * @param bool            $foreigncontext
     * @param bool            $wrongitem
     * @param bool            $missing
     * @param bool            $oversized
     * @param \stdClass|false $filerecord
     * @return \stdClass
     */
    protected static function build_issue_row(
        $question,
        array $questionusages,
        array $source,
        $ref,
        array $match,
        $owningcontextid,
        $foreigncontext,
        $wrongitem,
        $missing,
        $oversized,
        $filerecord
    ) {
        global $CFG;

        $issuetypes = [];
        if ($foreigncontext) {
            $issuetypes[] = 'foreign-context';
        }
        if ($wrongitem) {
            $issuetypes[] = 'wrong-item';
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
        $row->currentlyusedin = implode('; ', array_unique($courselabels)) ?: '(not currently used in any quiz)';
        $row->quizlinks = implode('; ', array_unique($quizlinks));
        $row->fileshouldbein = self::describe_context($owningcontextid);
        $row->embeddedurl = "pluginfile.php/{$match['contextid']}/{$match['component']}/{$match['filearea']}/"
            . "{$match['itemid']}/{$match['path']}";
        $row->embeddedcontextlabel = self::describe_context((int) $match['contextid']);
        $row->issuetypes = implode(',', $issuetypes);
        $row->filesize = $filerecord ? $filerecord->filesize : null;
        $row->editurl = $CFG->wwwroot . '/question/bank/editquestion/question.php?id=' . $question->id;

        return $row;
    }
}
