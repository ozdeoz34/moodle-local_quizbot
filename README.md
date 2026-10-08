# Quizbot for Moodle

Quizbot writes quiz questions from the teaching material a course already has. A teacher opens a quiz, presses
**Generate with Quizbot** next to *Add question*, chooses what the questions should be about, says how many of each
kind, and reviews every question before it is added. Nothing reaches the quiz until the teacher saves it.

This is a paid plugin: it needs a Quizbot licence key. Questions are written by the Quizbot service
(<https://quizbot.ai>), so the site must be able to reach it over HTTPS.

## Plans

| Plan | Who can use it | Questions a year |
|---|---|---|
| Teacher 500 | 1 teacher | 500 |
| Teacher 1,000 | 1 teacher | 1,000 |
| Teacher 2,000 | 1 teacher | 2,000 |
| School 20 | up to 20 teachers | unlimited, within fair use |
| School 50 | up to 50 teachers | unlimited, within fair use |
| School 100 | up to 100 teachers | unlimited, within fair use |

- A licence is for one Moodle site and runs for a year from the purchase.
- Only questions a teacher **adds to a quiz** are counted. Questions that are written and then discarded, unticked
  or rewritten are not.
- **Teacher packages can be bought again at any time.** Buy another one with the same e-mail address and it is
  added to the licence you have: its questions come on top of what is left, it brings one more teacher place, and
  the whole licence is good for a year from that purchase. The key stays the same.
- A teacher takes a place the first time he or she uses Quizbot, and keeps it for the licence's year.
- Each plan includes an allowance of video and audio that Quizbot reads (10, 20 and 40 hours a year for the
  teacher packages; 200, 500 and 1,000 hours for the school plans). Documents, pages and text are not limited.

## What a teacher can use as a source

Up to 4 sources in one run, mixed freely:

- **From this course** – files and text in File, Folder, Page, Book, Lesson and Label activities, YouTube videos
  linked from the course, and the course's quizzes (Quizbot writes new questions like the ones already there).
- **Upload a file** – PDF, Word, PowerPoint, Excel, text, pictures, video and audio, within the course's upload limit.
- **Link or video** – a public web page, a linked PDF/Word/PowerPoint/Excel file (up to 25 MB), or a YouTube video.
- **Paste text**.

## What it writes

Multiple choice, true/false, short answer, numerical, matching and essay questions, up to 40 in one run (at most 10
numerical, 5 matching and 5 essay). The teacher picks the language, level, difficulty, whether each answer gets
feedback, and whether the questions should be spread across all sources. Maths is written in LaTeX and shown by
Moodle's MathJax filter.

On the review page the teacher can tick or untick each question, edit it, ask Quizbot to rewrite it, and add the
uploaded files to the course as hidden resources. The teacher chooses whether the questions are kept for this quiz
only or in the course's shared question bank, where other quizzes can use them; either way they are added to the
quiz.

## Quizbot Analysis (teachers)

A *Quizbot Analysis* tab on every quiz, beside *Question bank*: how the class did, the score spread, topics (the
material each question was written from) weakest first, thinking skills (Bloom's levels), planned against real
difficulty, notes on single questions (very easy, few got it right, does not separate strong from weak students, a
wrong answer many gave), and the students below the pass mark with the topic each should revise first. In the
course's *Reports*, *Quizbot Analysis: the whole course* puts the course's quizzes together and lists the students who
need attention. Both pages follow the quiz's or course's groups, and offer each year as a whole ("All Grade 10").

Everything is worked out inside Moodle from the quiz attempts; nothing about students is sent to Quizbot.

## Remedial quizzes (teachers)

*Make remedial quizzes*, above the students below the pass mark, makes one short quiz for each topic they should
revise first. The teacher can leave a student out or move them to another topic, and chooses how many questions.
Quizbot writes new questions from that topic's material in the course, easy ones first, using the quiz's questions on
the topic as examples (their text only); the teacher checks them as on the usual review page and sets the quizzes up.
Each quiz gets a group of its students and an access restriction to that group, so only they see it (Moodle's
*Restricted access* must be on). Feedback after each answer with a hint and a second try, unlimited attempts and no
grade in the course total are the defaults. An optional Moodle message tells each student. Quizbot Analysis then shows
who has tried each quiz and how their result on the topic changed.

Making them needs `local/quizbot:generate` on the quiz and the right to add activities and manage groups in the
course (`moodle/course:manageactivities`, `mod/quiz:addinstance`, `moodle/course:managegroups`).

## For students

On the review page of a finished attempt, a card shows the result by topic and the topic to revise first, with a link
to its material in the course. *My progress* in the course's menu shows the student's quizzes, topics and skills, and
the questions to look at again. Students see only their own answers, and only when the quiz's review options show
them their marks.

## Quizbot usage (administrators)

*Site administration > Reports > Quizbot usage*: the licence, teachers who use Quizbot, the courses, questions added
week by week and how students did on them, with a CSV download. Students are only counted on this page.

## Requirements

- Moodle 4.1 to 5.2 (tested on 4.1, 4.2, 4.3, 4.4, 4.5, 5.0, 5.1 and 5.2; PHP 7.4 to 8.3; MySQL/MariaDB and
  PostgreSQL).
- Outgoing HTTPS from the Moodle server to `quizbot.ai`.
- A Quizbot licence key.

## Installation

1. Put the `quizbot` folder in `local/` (Moodle 5.1 and later: `public/local/`), or install the ZIP from
   *Site administration > Plugins > Install plugins*. From the source repository, clone it into that place under the
   name `quizbot` (for example `git clone <repository> local/quizbot`).
2. Visit *Site administration > Notifications* to finish the installation.
3. Enter the licence key in *Site administration > Plugins > Local plugins > Quizbot*.
4. *Site administration > Plugins > Local plugins > Quizbot licence* shows whether the key works, how many questions
   are left and until when.

## Settings

| Setting | What it is |
|---|---|
| Licence key | The key received with the purchase. |
| Quizbot server | `https://quizbot.ai`. Change it only if Quizbot support asks you to. |
| Support e-mail | Where the "Contact Quizbot" line at the foot of the wizard sends teachers. |

## Who can use it

| Capability | What it allows | Given by default to |
|---|---|---|
| `local/quizbot:generate` | Generating questions for a quiz (with `mod/quiz:manage`) | Editing teacher, Manager |
| `local/quizbot:viewanalysis` | Quizbot Analysis of quizzes and courses (with `mod/quiz:viewreports`) | Teacher, Editing teacher, Manager |
| `local/quizbot:viewownprogress` | The result card and *My progress* | Student |

## Privacy

Only what the teacher chooses is sent to the Quizbot service: the files, text, links and existing quiz questions
picked as sources, and the options chosen. No student data is sent. The Quizbot service deletes files as soon as it
has read them. It keeps the text read from the material only until the questions are added or discarded, and for
two days at most (so that single questions can be rewritten during the review). The questions written are deleted
there 30 days later; only their number is kept, for the licence.

In Moodle, the plugin keeps each teacher's wizard run (choices, uploaded files and the questions written) until it is
finished; the scheduled task *Remove old Quizbot wizard runs* clears finished and abandoned runs. For each question
added it keeps what Quizbot found out about it (topic, skill, difficulty, the material) and the teacher who added it;
the record goes with the question. The analysis pages keep their results, students' scores included, for 15 minutes
in Moodle's cache. The plugin's own tables hold nothing about students, except which students a teacher chose for a
remedial quiz, and only until that quiz is made (from then on its group says who it is for) or given up. For each
remedial quiz it keeps which quiz it came from, its topic and the teacher who made it. The plugin implements Moodle's
Privacy API, so this data is included in data exports and deletion requests.

Links are opened by the Moodle server with Moodle's own security settings; addresses inside the local network
(loopback, private and link-local addresses) are always refused.

## Support

E-mail <info@quizbot.ai>. The "Contact Quizbot" line at the foot of every wizard page opens a pre-addressed e-mail.
Problems and ideas can also be reported in the issue tracker of the plugin's public source repository.

## Licence

Copyright 2026 Capstone Edu Ltd. This program is free software: you can redistribute it and/or modify it under the
terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version. The Quizbot service it connects to is a paid service.
