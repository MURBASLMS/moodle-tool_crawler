[![ci](https://github.com/catalyst/moodle-tool_crawler/actions/workflows/ci.yml/badge.svg?branch=MOODLE_310_STABLE)](https://github.com/catalyst/moodle-tool_crawler/actions/workflows/ci.yml?branch=MOODLE_310_STABLE)

# moodle-tool_crawler

* [What is this?](#what-is-this)
* [How does it work?](#how-does-it-work)
* [Branches](#branches)
* [Installation](#installation)
* [Configuration](#configuration)
* [Testing](#testing)
* [Auditing quiz question images/files](#auditing-quiz-question-imagesfiles-without-a-full-http-crawl)
* [Debugging](#debugging)
* [Reports](#reports)
* [Support](#support)
* [Warm thanks](#warm-thanks)

# What is this?

This is a link checking robot, that crawls your Moodle site following links
and reporting on links that are either broken or that link to very large
files.

https://moodle.org/plugins/tool_crawler

# How does it work?

It is an admin tool plugin with a Moodle cron task. It logs into your Moodle
via curl effectively from outside Moodle. The cronjob scrapes each page,
parses it and follows links. By using this architecture it will only find
broken links that actually matter to students.

Since the plugin cronjob comes in from outside it needs to authenticate in Moodle.

# Branches

| Moodle version     | Branch                |
| ------------------ | --------------------- |
| Moodle 3.10 - 4.5+ | MOODLE_310_STABLE     |
| Moodle 3.4 to 3.9  | master                |
| Totara 12+         | master                |

# Installation

The plugin has a dependency on the [moodle-auth_basic](https://moodle.org/plugins/auth_basic).
To install the dependency plugin as a git submodule:
```
git submodule add git@github.com:catalyst/moodle-auth_basic.git auth/basic
```


Install plugin moodle-tool_crawler as a git submodule:
```
git submodule add git@github.com:catalyst/moodle-tool_crawler.git admin/tool/crawler
```
# Configuration

When installing the plugins please keep in mind the official Moodle recommendations: [installing Moodle plugins](https://docs.moodle.org/32/en/Installing_add-ons)

## Step 1

Login to Moodle after you have downloaded the plugin code with git. You will be
forwarded to URL http://your_moodle_website.com/admin/index.php with Plugins check.
There you should see plugins "Basic authentication" and "Link checker robot".

Click button "Upgrade Moodle database now" which should initiate plugins installation.

Now you should see page "Upgrading to new version" with plugins installation
statuses and button "Continue".

**Note! Plugin auth_basic is disabled by default after installation.
You will need to enable it manually from 


Home ► Site administration ► Plugins ► Authentication ► Manage authentication**

After clicking "Continue" you will get to the page "New settings - Link checker robot".
While you may leave other settings default, you might want to setup a custom bot username
and make sure to change bot password.

**It is recommended that bot user should be kept with readonly access to all
the site pages you wish to crawl. You can give the robot similar read
capabilities that real students have. Never give your bot user write capabilities.**

It can also be a good idea to give your robot some extra permissions, like visibility of hidden courses
or activites so it can crawl content which is being developed and will be later delivered to students.
If you are worried about load and total crawl time then you can filter out whole courses, eg last years
archives courses, see below for more details.

After verifying all settings click "Save changes".

## Step 2

Enable auth_basic plugin (if you haven't done that earlier) from

Home ► Site administration ► Plugins ► Authentication ► Manage authentication

Now navigate to URL http://your_moodle_website.com/admin/tool/crawler/index.php".
It will show some stats about the Link checker Robot.

Click "Auto create" button against "Bot user". This actually creates the user
with the username and password you have configured previously on page
"New settings - Link checker robot".

Once bot user is created "Bot user" line in status report should be showing "Good".

## Disabling crawling of specific course categories

This is achieved by configuring proper security roles in Moodle and assigning
these roles to the robot user on desired categories.

Import role "Robot" from admin/tool/crawler/roles/robot.xml on

Site administration ► Users ► Permissions ► Define roles ► Add a new role

Add this role to the "Link checker robot" user on


Site administration ► Users ► Permissions ► Assign system roles.

Import role "Robot nofollow" from file 
admin/tool/crawler/roles/robotnofollow.xml on 


Site administration ► Users ► Permissions ► Define roles ► Add a new role.

To disable crawling of, say "Category ABC", go to


Site administration ► Courses ► Manage courses and categories ► Category ABC

then click on "Assign roles" in the left navigation menu.
Click on role "Robot nofollow", click on user "Link checker Robot"
under "Potential users" and add him to "Existing users".

The above configuration applies role "Robot" on the whole Moodle site
and lets crawler to access general content. And "Role nofollow" prohibits
crawler from accessing the specific category.

In the same way it is possible to restrict crawler from accessing other
Moodle contexts such as courses, activities and blocks.

The same effect could be achieved even without role "Robot nofollow" by
assigning role "Robot" on the contexts you want to be crawled. But
using the combination of two roles gives more flexibility.

# Testing

## Test basic authentication with curl

Example in bash:

```
curl -c /tmp/cookies -v -L --user moodlebot:moodlebot http://your_moodle_website.com/course/view.php?id=3
```

This command should log you in with specified credentials via Basic HTTP Auth.
It will dump headers, requests and responses and among the output you should
be able to see the line "You are logged in as ".

Once Basic HTTP auth works test running the robot task from the CLI:

```
php admin/cli/scheduled_task.php --execute='\tool_crawler\task\crawl_task'
Execute scheduled task: Parallel crawling task (tool_crawler\task\crawl_task)
... used 22 dbqueries
... used 0.039698123931885 seconds
Scheduled task complete: Parallel crawling task (tool_crawler\task\crawl_task)
```

This will create a batch of new adhoc crawl tasks in the mdl_task_adhoc table that
will run in parallel, depending on the crawl_task setting. 

You can manually run the adhoc tasks from the CLI with:
```
php admin/cli/adhoc_task.php --execute
Execute adhoc task: tool_crawler\task\adhoc_crawl_task
... used 5733 dbqueries
... used 58.239180088043 seconds
Adhoc task complete: tool_crawler\task\adhoc_crawl_task
```

If this worked then it's a matter of sitting back and waiting for the
robot to do it's thing. It works incrementally spreading the load over many
cron cycles, you can watch it's progress in

/admin/tool/crawler/report.php?report=queued

and

/admin/tool/crawler/report.php?report=recent

# Auditing quiz question images/files (without a full HTTP crawl)

If all you actually care about is whether images/files embedded in quiz
questions (question text, feedback, answers) will load for real *students*,
a full site HTTP crawl is slower and less targeted than necessary, and won't
catch the most common real cause of this class of problem: a question was
authored, or rolled over from a previous course offering, with a raw
`pluginfile.php` URL pasted into the HTML source (instead of using the file
picker). A properly authored image (inserted via the file picker) is always
saved by Moodle with a `pluginfile.php` URL whose contextid/itemid are the
question's *own* current values - fixed at save time, and the same
regardless of which course/quiz is currently using the question. So the
reliable check is simply: does the embedded reference still point at this
exact question's own context/item, or does it point at something else
entirely (typically a raw absolute URL pasted from a different, unrelated -
often "old unit" - question/course)? If it points elsewhere, no amount of
enrolment in the current course will make Moodle serve it, because it isn't
actually this question's file at all - while staff who happen to also have
access to that other, unrelated context won't notice anything wrong when
they do a quick check themselves.

Note: while composing/editing, the editor's source-code view shows
`draftfile.php` URLs (a private, temporary per-editing-session area), and
viewing a question within a live quiz attempt shows a different, dynamically
generated `pluginfile.php/.../usageid/slot/itemid/...` form - neither of
which reflect what's actually stored in the database. Properly authored
content (inserted via the file picker) is normally stored as an
`@@PLUGINFILE@@/filename` **placeholder token**, not a concrete URL at all -
Moodle resolves it to whichever of the above forms is appropriate at render
time. This tool only ever reads the actual persisted database content, so it
checks both forms it might actually find there:

- A literal, concrete `pluginfile.php/<contextid>/.../<itemid>/...` URL -
  which only ends up stored if someone bypassed the file picker (e.g. pasted
  a raw URL into the HTML source). This is the rarer, but more seriously
  broken, case: the checks below (foreign-context/wrong-item) only apply to
  this form, since a token can't be "foreign" by construction.
- An `@@PLUGINFILE@@/filename` token - the normal/expected form. This can
  still reference a filename that's missing or oversized, even though it can
  never be pointing at the wrong course/context.

`cli/check_question_images.php` audits this directly against the database
(no HTTP requests at all, runs in seconds/minutes even across a whole site).
It checks:

- **foreign-context**: the contextid embedded in the URL doesn't match this
  question's own owning question-bank category context - i.e. it belongs to
  a different question/category entirely. Almost always unfixable by
  enrolment; the image needs to be re-inserted via the file picker.
- **wrong-item**: the context matches, but the itemid doesn't match this
  question's (or this specific answer's/hint's) own id - it's referencing a
  sibling question's/answer's file, not this one's.
- **missing-file**: the referenced file no longer exists at all.
- **oversized**: the referenced file is at or above a configurable size
  threshold (default 1 MB).

Only questions that are actually referenced by a live quiz (via
`question_references`) are scanned - an unused question sitting in the
question bank can't affect any student, so is intentionally out of scope.
Importantly, a quiz slot can *pin a specific version* of a question rather
than always tracking the latest edit (e.g. deliberately, for exam
stability) - this tool resolves and scans the actual pinned/shown version
per usage, not just "the latest version of every entry", so it won't miss
(or misreport against) content that's since been edited but isn't what
students are actually shown. Randomly-drawn "random from category" slots
(`question_set_references`) are not yet resolved to their pool of possible
questions and are not covered.

```
php admin/tool/crawler/cli/check_question_images.php
php admin/tool/crawler/cli/check_question_images.php --courseid=1234
php admin/tool/crawler/cli/check_question_images.php --oversizebytes=2097152 --format=csv
```

This deliberately covers the highest-traffic core question types first
(multichoice, truefalse, shortanswer, essay, match, numerical - see
`question_image_audit::get_content_sources()`), rather than being exhaustive
for every third-party question type out of the box; add more table/field
entries there if you use other question types and want them covered too.

# Debugging

You can also run link crawler on given page by passing url. You might need to Reset Progress if its still running from Administration > Reports > Link crawler -> Robot status

```
php admin/tool/crawler/cli/crawl-as.php --url=http://localhost/
```

# Reports

4 new admin reports are available for showing the current crawl status, broken
links and URLs and slow links. They are available under:

Administration > Reports > Link checker

# Support

Please raise any issues in GitHub:

https://github.com/catalyst/moodle-tool_crawler/issues

If you need anything urgently and would like to sponsor it's implementation please
email me: [Brendan Heywood](mailto:brendan@catalyst-au.net).



Warm thanks
-----------

Thanks to Central Queensland University for sponsoring the initial creation of this plugin:

https://www.cqu.edu.au/

This plugin was developed by Catalyst IT Australia:

https://www.catalyst-au.net/

<img alt="Catalyst IT" src="https://cdn.rawgit.com/CatalystIT-AU/moodle-auth_saml2/master/pix/catalyst-logo.svg" width="400">
