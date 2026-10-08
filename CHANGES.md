# Changes

## 1.2.0 (2026-10-08) – the first release after 1.0.0; it includes 1.1.0, which was not published on its own

- Make remedial quizzes: one button above "Students below the pass mark" in a quiz's Quizbot Analysis. Quizbot
  groups those students by the topic each should revise first; the teacher unticks or moves students and chooses the
  number of questions; Quizbot writes new questions from each topic's own material, like the ones they got wrong but
  never the same, easy ones first; the teacher checks them (one tab per quiz) and sets the quizzes up. Each quiz is
  made with a group of its own students and an access restriction, so only they see it; feedback after each answer
  with a hint and a second try, unlimited attempts, not counted in the course total (all changeable); an optional
  Moodle message tells each student. Students see it at the top of "My progress"; the analysis shows who has tried
  and how their topic result moved. The questions count on the licence like any others.
- Remedial quizzes are left out of the whole-course analysis (they are practice for a few students).
- The quiz's Questions page, for a quiz with Quizbot questions: when the questions' marks add up to another number than
  the maximum grade (11 marks, graded out of 10), "Total of marks" is marked and a line explains that Moodle scales
  every student's marks to the maximum grade, with a button to grade out of the total instead.
- "My progress" sits on the course's line of tabs and, for students, on each quiz's; "Quizbot Analysis" beside
  "Question bank".
- Question names show words written inside a formula (\text{m/s}) as plain words.

## 1.1.0 (2026-10-08, not published on its own)

- Thinking skills (Bloom's taxonomy): on the questions step a teacher can choose how many questions test each of
  the six levels, with quick choices and a table of how Quizbot will write them; numbers that cannot be met are
  explained before anything is sent. Each question comes back labelled with the skill it really tests.
- Review page: each question shows its skill, a row of buttons shows the questions of one skill, and the page says
  when the material did not allow a skill as often as asked. The edit page can change a question's skill;
  "Rewrite this one" keeps it.
- Every question added with Quizbot is recorded with its topic, skill and difficulty, for Quizbot Analysis. The
  record goes when the question is deleted; the Privacy API exports it and removes the teacher from it.
- Quizbot Analysis of a quiz, for its teachers (its own tab on the quiz's line of tabs, beside "Question bank"; under
  "More" when the screen is narrow): finished attempts, class average and middle score, pass mark (the quiz's "Grade to
  pass", else 60%), score spread, topics weakest first, skills, planned against real difficulty, every question with
  notes (very easy, few got it right, does not separate strong from weak, a wrong answer a third of the class gave),
  and the students below the pass mark with the topic each should revise first and Moodle's own message window.
  Each student's first finished attempt counts, never a preview; the quiz's groups apply; worked out in Moodle and
  kept 15 minutes. New capability local/quizbot:viewanalysis (with mod/quiz:viewreports).
- For students: a card at the top of a finished attempt's review page with their result by topic and the topic to
  revise first, with a button to its material in the course; and "My progress" in the course's menu - quizzes done
  and the next one, their average, strongest and weakest topic, every topic with its material, skills, their quizzes
  and questions to look at again. Built from the student's latest answer to each question, never another student's,
  and only when the quiz's review options let the student see the marks. New capability
  local/quizbot:viewownprogress (students). Questions added from now on remember their material's course item.
- Quizbot Analysis of a whole course, for its teachers (in the course's Reports): every quiz with Quizbot questions
  and how it went, topics and skills across all of them, and the students who need attention (an average below 60%
  over two or more quizzes), with the way their scores are going and the topic to revise first.
- Grade levels: the groups menu of both analysis pages has a line for each year above its classes ("All Grade 10"
  above 10A and 10B), from the course's groupings or from classes named alike. A teacher who may see only their own
  groups sees only those.
- Quizbot usage, for site administrators (Site administration > Reports): the licence, teachers active, courses,
  questions added week by week, how students did on them, and a CSV download. Students are only counted there.
- The plugin's pages in the quizbot.ai look: a paper "desk" with the page title, the site's typefaces (shipped in
  fonts/, SIL Open Font License; nothing is loaded from outside the site), ink buttons, hairline cards. Only the
  plugin's own pages and its button on the quiz change; the rest of Moodle keeps the school's theme.

## 1.0.0 (2026-10-07) – first release on the Moodle Marketplace

- Plans: teacher packages (500, 1,000 or 2,000 questions for one teacher) and school plans (up to 20, 50 or 100
  teachers, questions not counted). The licence page shows the plan, teacher places in use and the video and
  audio allowance; the wizard shows "unlimited" for a school plan.
- When a teacher package is used up, or its teacher place is taken, the page says so and shows how to buy another
  package, which is added to the same licence at once.
- The plugin tells the Quizbot service which teacher is asking as a one-way code (no name, no e-mail address), so
  teacher places can be counted. The privacy description says so.

## 0.7.3 (2026-10-06)

- Right-to-left pages: question text, names and edit boxes take their direction from their own text.

## 0.7.2 (2026-10-06)

- Built with Moodle's own JavaScript build (grunt), with source maps.
- Code checked against Moodle's coding standard, PHPDoc rules and template validation.
- Privacy description says exactly how long the Quizbot service keeps text and questions.

## 0.7.1 (2026-10-06)

- Links: every address is checked before it is opened, redirects included; addresses inside the local network are
  always refused, whatever the site's own list of blocked hosts says.
- Automated tests (PHPUnit) for saving questions, finding sources, editing, links, licences, clean-up and privacy.
- The Privacy API also covers the files a teacher uploads and the files fetched from links.

## 0.7.0 (2026-10-06)

- Licence state in Moodle: a page for administrators, questions left on the questions step, a clear page instead of
  the wizard when the licence cannot be used.
- Daily task that removes old wizard runs.

## 0.6.0 – 0.6.2 (2026-10-06)

- Review page: edit a question, have it rewritten, untick it, add the uploaded files to the course.
- Maths written as LaTeX and shown by Moodle's MathJax filter; readable question names.
- "Contact Quizbot" line on every wizard page.

## 0.3.0 – 0.5.1 (2026-10-06)

- Sources: "Link or video" tab (web page, linked document or YouTube video) and the course's quizzes.
- Runs on Moodle 4.1 to 5.2.
- At most 40 questions in one run: 10 numerical, 5 matching and 5 essay questions at most.

## 0.1.0 – 0.2.5 (2026-10-05 – 06)

- First versions: the Generate button on the quiz, sources, questions, progress and review steps, questions added to
  the quiz.
