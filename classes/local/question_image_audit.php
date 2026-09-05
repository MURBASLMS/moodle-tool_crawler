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
 * URL whose contextid/itemid are the question's *own*, current values - that is, the question's owning
 * question_categories.contextid, and (depending on field) either the question's own id or the specific
 * answer/hint row's own id. This is fixed at save time and does not depend on, or vary by, which
 * course/quiz is currently using the question - a question pulled from a shared/faculty-level question
 * bank is expected to keep referencing that bank's own context regardless of which course's quiz uses it.
 *
 * So the one reliable question to ask of every embedded pluginfile.php reference is: does it still point
 * at *this exact question's own* current context/item, or does it point at something else entirely
 * (typically: a different, unrelated, "old unit" question/course - usually because a raw absolute URL
 * was pasted into the HTML source rather than inserted via the file picker/file import mechanism)? If it
 * points at something else, no amount of enrolment in the current course will make Moodle serve it,
 * because it isn't actually this question's file at all - while staff who happen to also have access to
 * that other, unrelated context won't notice anything wrong when they view it themselves.
 *
 * Crucially, "the question" a quiz slot uses is resolved via {question_references}, which can *pin a
 * specific version* of a question rather than always tracking the latest edit
 * ({question_references}.version is not null). A quiz set up for exam-stability reasons may well be
 * showing students an older version than whatever is currently the latest in the question bank, so this
 * scans the *actual pinned/resolved version per usage*, not just "the latest version of every entry" -
 * otherwise a since-edited (or since-fixed, or since-broken) later version could be scanned instead of
 * what students actually see.
 *
 * Only questions that are actually referenced by a live quiz (via question_references) are scanned - a
 * question sitting unused in the question bank can't affect any student, so is out of scope by design.
 * (Randomly-drawn questions via question_set_references - "random from category" slots - are not yet
 * resolved to their possible pool of underlying questions and are not covered.)
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
     * Content sources to scan for embedded pluginfile.php references, plus 'itemidbasis' - what the
     * itemid in a *legitimate* pluginfile.php reference for that field should equal:
     *  - 'question': the question's own id (used for the question's own text/feedback areas).
     *  - 'row':      the specific source row's own id (used for per-answer/per-hint areas).
     *  - null:       not confidently known for this field, so the itemid isn't strictly checked (only
     *                the context is), to avoid false positives.
     *
     * 'fields' maps each database column name to its corresponding Moodle file area name - these are not
     * always the same (e.g. question_answers.feedback is stored under the 'answerfeedback' file area, not
     * 'feedback'). This mapping is what lets us resolve @@PLUGINFILE@@ tokens (which don't carry a
     * filearea themselves) back to a concrete, checkable context/component/filearea/itemid. A null
     * filearea means it isn't confidently known for that field, so @@PLUGINFILE@@ tokens in it are not
     * checked (only literal, concrete pluginfile.php URLs are, same as when itemidbasis is null).
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
            [
                'table' => 'question',
                'idfield' => 'id',
                'fields' => ['questiontext' => 'questiontext', 'generalfeedback' => 'generalfeedback'],
                'itemidbasis' => 'question',
            ],
            [
                'table' => 'question_hints',
                'idfield' => 'questionid',
                'fields' => ['hint' => 'hint'],
                'itemidbasis' => 'row',
            ],
            [
                'table' => 'question_answers',
                'idfield' => 'question',
                'fields' => ['answer' => 'answer', 'feedback' => 'answerfeedback'],
                'itemidbasis' => 'row',
            ],
            [
                'table' => 'qtype_multichoice_options',
                'idfield' => 'questionid',
                'fields' => [
                    'correctfeedback' => 'correctfeedback',
                    'partiallycorrectfeedback' => 'partiallycorrectfeedback',
                    'incorrectfeedback' => 'incorrectfeedback',
                ],
                'itemidbasis' => 'question',
            ],
            [
                'table' => 'qtype_match_options',
                'idfield' => 'questionid',
                'fields' => [
                    'correctfeedback' => 'correctfeedback',
                    'partiallycorrectfeedback' => 'partiallycorrectfeedback',
                    'incorrectfeedback' => 'incorrectfeedback',
                ],
                'itemidbasis' => 'question',
            ],
            [
                'table' => 'qtype_match_subquestions',
                'idfield' => 'questionid',
                'fields' => ['questiontext' => null],
                'itemidbasis' => null,
            ],
            [
                'table' => 'qtype_essay_options',
                'idfield' => 'questionid',
                'fields' => ['graderinfo' => null, 'responsetemplate' => null],
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
     * Matches Moodle's @@PLUGINFILE@@/<path> placeholder token - the normal, properly-authored way an
     * image/file inserted via the file picker is actually stored. Unlike a concrete pluginfile.php URL,
     * this carries no context/component/filearea/itemid of its own - those are always implicitly "this
     * exact field, on this exact question/row", resolved dynamically at render time. So a token can never
     * be "foreign-context" or "wrong-item" by construction - but it can still point at a filename that no
     * longer exists, or that's oversized.
     */
    const TOKEN_REGEX = '#@@PLUGINFILE@@/(?<path>[^"\'\)\s<>]*)#';

    /**
     * Runs the audit.
     *
     * @param array      $options {
     *     @type int      $courseid       Restrict to a quiz in this course (0 = every quiz on the site).
     *     @type int      $oversizebytes  Flag files at or above this size. Defaults to self::DEFAULT_OVERSIZE_BYTES.
     *     @type int      $limit          Maximum number of issue rows to return (0 = unlimited).
     * }
     * @param array|null $stats Optional, passed by reference. Populated with scan stats for --verbose
     *                          reporting: usagesfound, questionsscanned, quizzesmatched, quiznames,
     *                          fieldsscanned, pluginfilerefsfound, issuesfound, durationseconds.
     * @return array Array of stdClass issue rows, see build_issue_row().
     */
    public static function run(array $options = [], ?array &$stats = null) {
        $starttime = microtime(true);

        $stats = [
            'usagesfound'         => 0,
            'questionsscanned'    => 0,
            'quizzesmatched'      => 0,
            'quiznames'           => [],
            'fieldsscanned'       => 0,
            'pluginfilerefsfound' => 0,
            'tokenrefsfound'      => 0,
            'issuesfound'         => 0,
            'durationseconds'     => 0,
        ];

        $courseid      = $options['courseid'] ?? 0;
        $oversizebytes = $options['oversizebytes'] ?? self::DEFAULT_OVERSIZE_BYTES;
        $limit         = $options['limit'] ?? 0;

        // Every quiz-slot usage in scope (a single course's quizzes, or every quiz on the site), with
        // enough information to resolve exactly which question *version* is actually shown to students.
        $rawusages = self::get_raw_usages($courseid);
        $stats['usagesfound'] = count($rawusages);
        if (empty($rawusages)) {
            $stats['durationseconds'] = round(microtime(true) - $starttime, 3);
            return [];
        }

        // Resolve each usage's actual question id: the pinned version if question_references.version is
        // set, otherwise the latest version - never just "whatever the latest version happens to be",
        // regardless of what's actually pinned.
        $entryids = array_unique(array_column($rawusages, 'questionbankentryid'));
        $versionsbyentry = self::get_versions_by_entry($entryids);

        $usagesbyquestionid = [];   // questionid => [ {courseid, coursefullname, cmid, quizname}, ... ].
        $entrybyquestionid = [];    // questionid => questionbankentryid.
        $questionids = [];
        foreach ($rawusages as $usage) {
            $versions = $versionsbyentry[$usage->questionbankentryid] ?? [];
            if (empty($versions)) {
                continue; // Entry has no versions at all (shouldn't normally happen) - skip.
            }
            if (!empty($usage->pinnedversion) && isset($versions[(int) $usage->pinnedversion])) {
                $questionid = $versions[(int) $usage->pinnedversion];
            } else {
                // version = null means "always use the latest ready version"; also falls back here if a
                // pinned version number no longer exists (e.g. deleted).
                $questionid = $versions[max(array_keys($versions))];
            }

            $questionids[$questionid] = true;
            $entrybyquestionid[$questionid] = $usage->questionbankentryid;
            $usagesbyquestionid[$questionid][] = $usage;
        }
        $questionids = array_keys($questionids);

        if (empty($questionids)) {
            $stats['durationseconds'] = round(microtime(true) - $starttime, 3);
            return [];
        }

        // Now fetch the actual question rows (name/qtype) for exactly those resolved question ids.
        $questionmap = self::get_questions_by_id($questionids);
        foreach ($questionmap as $qid => $q) {
            $q->questionbankentryid = $entrybyquestionid[$qid];
        }
        $stats['questionsscanned'] = count($questionmap);

        // The question's own, current owning context - the *only* legitimate context for a pluginfile.php
        // reference embedded in that question's own content, regardless of which course/quiz uses it.
        $owningcontexts = self::get_owning_contexts_by_entry(array_values($entrybyquestionid));

        $matchedquizzes = [];
        foreach ($usagesbyquestionid as $qusages) {
            foreach ($qusages as $usage) {
                $matchedquizzes[$usage->cmid] = $usage->quizname . ' (course id ' . $usage->courseid . ')';
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

                $questionusages = $usagesbyquestionid[$questionid] ?? [];

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

                // @@PLUGINFILE@@ tokens - the normal, properly-authored form. These carry no
                // context/component/filearea/itemid of their own (always implicitly "this exact field on
                // this exact question/row"), so they can never be foreign-context/wrong-item by
                // construction - but the filename they point at can still be missing or oversized. Only
                // checked where we confidently know the expected context/itemid/filearea for this field.
                if ($owningcontextid !== null && $expecteditemid !== null && $ref->filearea !== null) {
                    foreach (self::find_token_refs($ref->text) as $tokenmatch) {
                        $stats['tokenrefsfound']++;

                        $match = [
                            'contextid' => $owningcontextid,
                            'component' => 'question',
                            'filearea'  => $ref->filearea,
                            'itemid'    => $expecteditemid,
                            'path'      => $tokenmatch['path'],
                        ];

                        $filerecord = self::find_file_record($match);
                        $missing    = ($filerecord === false);
                        $oversized  = ($filerecord && $filerecord->filesize >= $oversizebytes);

                        if (!$missing && !$oversized) {
                            continue; // Token resolves fine, nothing wrong.
                        }

                        $issues[] = self::build_issue_row(
                            $q,
                            $questionusages,
                            $source,
                            $ref,
                            $match,
                            $owningcontextid,
                            false,
                            false,
                            $missing,
                            $oversized,
                            $filerecord,
                            true
                        );
                        $stats['issuesfound'] = count($issues);

                        if ($limit && count($issues) >= $limit) {
                            $stats['durationseconds'] = round(microtime(true) - $starttime, 3);
                            return $issues;
                        }
                    }
                }
            }
        }

        $stats['durationseconds'] = round(microtime(true) - $starttime, 3);
        return $issues;
    }

    /**
     * Returns every quiz-slot usage in scope: question_references rows joined to the quiz/course-module/
     * course actually using them, optionally restricted to one course.
     *
     * @param int $courseid 0 = every quiz on the site.
     * @return array Array of stdClass {refid, questionbankentryid, pinnedversion, usingcontextid, cmid,
     *               courseid, coursefullname, quizname}.
     */
    protected static function get_raw_usages($courseid) {
        global $DB;

        $params = [];
        $coursefilter = '';
        if ($courseid) {
            $coursefilter = 'AND cm.course = :courseid';
            $params['courseid'] = $courseid;
        }

        $sql = "SELECT qr.id AS refid, qr.questionbankentryid, qr.version AS pinnedversion, qr.usingcontextid,
                       cm.id AS cmid, c.id AS courseid, c.fullname AS coursefullname, quiz.name AS quizname
                  FROM {question_references} qr
                  JOIN {context} ctx ON ctx.id = qr.usingcontextid AND ctx.contextlevel = " . CONTEXT_MODULE . "
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {quiz} quiz ON quiz.id = cm.instance
                  JOIN {course} c ON c.id = cm.course
                 WHERE 1 = 1 $coursefilter";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns questionbankentryid => [version => questionid, ...] for every version of every given entry.
     *
     * @param array $entryids
     * @return array
     */
    protected static function get_versions_by_entry(array $entryids) {
        global $DB;

        $entryids = array_unique(array_filter($entryids));
        if (empty($entryids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($entryids);
        $sql = "SELECT qv.questionbankentryid, qv.version, qv.questionid
                  FROM {question_versions} qv
                 WHERE qv.questionbankentryid $insql";

        $byentry = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $byentry[$row->questionbankentryid][(int) $row->version] = (int) $row->questionid;
        }
        $rs->close();

        return $byentry;
    }

    /**
     * Returns questionid => {id, name, qtype} for exactly the given question ids.
     *
     * @param array $questionids
     * @return array
     */
    protected static function get_questions_by_id(array $questionids) {
        global $DB;

        if (empty($questionids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($questionids);
        $sql = "SELECT id, name, qtype FROM {question} WHERE id $insql";

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
        $dbfields = array_keys($source['fields']);
        $fieldlist = implode(', ', $dbfields);
        $sql = "SELECT id, {$source['idfield']} AS questionid, $fieldlist
                  FROM {{$source['table']}}
                 WHERE {$source['idfield']} $insql";

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            foreach ($dbfields as $field) {
                if (empty($row->$field)) {
                    continue;
                }
                $ref = new \stdClass();
                $ref->questionid = $row->questionid;
                $ref->rowid = $row->id;
                $ref->table = $source['table'];
                $ref->field = $field;
                $ref->filearea = $source['fields'][$field];
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
     * @param string $text
     * @return array Array of matches, each a ['path' => ..].
     */
    protected static function find_token_refs($text) {
        if (!preg_match_all(self::TOKEN_REGEX, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $refs = [];
        foreach ($matches as $m) {
            $refs[] = ['path' => rawurldecode($m['path'])];
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

        // Strip a trailing cache-busting query string (e.g. "?1773639139900") and/or fragment - Moodle
        // commonly appends these to force a browser refetch after a file is re-uploaded/replaced. They are
        // not part of the actual stored filename, so must not be included when looking it up.
        $filename = preg_replace('/[?#].*$/', '', $filename);

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
        $filerecord,
        $istoken = false
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
        $row->embeddedurl = $istoken
            ? "@@PLUGINFILE@@/{$match['path']} (resolves to contextid={$match['contextid']}, "
                . "component={$match['component']}, filearea={$match['filearea']}, itemid={$match['itemid']})"
            : "pluginfile.php/{$match['contextid']}/{$match['component']}/{$match['filearea']}/"
            . "{$match['itemid']}/{$match['path']}";
        $row->embeddedcontextlabel = self::describe_context((int) $match['contextid']);
        $row->issuetypes = implode(',', $issuetypes);
        $row->filesize = $filerecord ? $filerecord->filesize : null;
        $firstusage = reset($questionusages);
        $row->editurl = $CFG->wwwroot . '/question/bank/editquestion/question.php?id=' . $question->id
            . ($firstusage ? '&courseid=' . $firstusage->courseid : '');

        return $row;
    }
}
