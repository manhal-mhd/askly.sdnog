# Daily Network Security Challenge – Scripts

This folder contains CLI scripts to import questions, create 30 daily quizzes, fix quiz settings, and keep your course tidy.

## Prerequisites
- Run as the web server user (usually `www-data`).
- Moodle is installed at `/var/www/moodle` and accessible via CLI.
- You have a course to host the quizzes (default: id=2, Exam Repository).
- Files are here:
  - `Daily_Network_Security_Challenge.gift`
  - `make_daily_quizzes.php`

## Files
- `make_daily_quizzes.php`: Creates Day 01–30 quizzes and attaches matching questions.
- `reset_and_recreate_daily.php`: Clean rebuild — deletes existing Day quizzes/questions, imports GIFT, moves questions to course context, deletes the temporary holder, and recreates quizzes.
- `cleanup_import_holder.php`: Removes any leftover "Daily import holder (temporary)" activities and migrates their questions to a course-level category.
- `fix_quiz_behaviour.php`: Sets quiz behaviour to a valid mode (default: `adaptive`) for all Day quizzes.
- `check_quiz_slots.php` (optional diagnostic): Inspect quiz slots and schema.

## Quick Start
- Clean rebuild (import + recreate):
```bash
sudo -u www-data php /var/www/moodle/local/scripts/reset_and_recreate_daily.php
```
- Create quizzes only (assumes questions already exist):
```bash
sudo -u www-data php /var/www/moodle/local/scripts/make_daily_quizzes.php
```
- Fix question behaviour for existing Day quizzes:
```bash
sudo -u www-data php /var/www/moodle/local/scripts/fix_quiz_behaviour.php
```
- Remove leftover temporary holders:
```bash
sudo -u www-data php /var/www/moodle/local/scripts/cleanup_import_holder.php
```

## How it works
- GIFT import: `reset_and_recreate_daily.php` imports questions from `Daily_Network_Security_Challenge.gift` into a temporary module context (required by the importer), then moves them to a course-level category and deletes the temporary module so it never stays visible.
- Quiz creation: `make_daily_quizzes.php` creates 30 quizzes and attaches one question per quiz using Moodle’s API (`quiz_add_quiz_question`). It searches for questions by name pattern `Day XX%` (e.g., `Day 01 - Beginner - Firewall Purpose`).
- Behaviour: Quizzes are created with `preferredbehaviour = 'adaptive'` and `shuffleanswers = 1`.

## Configuration
Inside `make_daily_quizzes.php` you can tune:
- `courseid`: target course (default: 2).
- `giftpath`: path to the GIFT file (default: `/var/www/moodle/local/scripts/Daily_Network_Security_Challenge.gift`).
- `startdate`, `openhour`, `durationseconds`, `timelimitseconds`, `attemptsallowed`.
- Question lookup: it uses `LIKE 'Day XX%'`. If your naming differs, adjust the SQL in the script or ask to switch to category-driven mapping.

Inside `reset_and_recreate_daily.php`:
- `courseid`, `giftpath` as above.
- Auto-moves imported questions to a course category named `Daily Network Security Challenge` and deletes the temporary holder.

## Troubleshooting
- Unknown question behaviour: Run `fix_quiz_behaviour.php` to set a valid behaviour (e.g., `adaptive`).
- "Invalid types" warnings: Use `reset_and_recreate_daily.php` to clean out legacy/broken slots and recreate using the quiz API.
- Quizzes created but no questions: Ensure the GIFT questions are imported and named `Day 01 …` through `Day 30 …`, or update the lookup logic.
- Permission errors: Always run as `www-data` using `sudo -u www-data`.

## Notes
- These scripts use Moodle’s official APIs (`add_moduleinfo`, `quiz_add_quiz_question`) to preserve integrity.
- The temporary import holder is created only during import and is deleted automatically in the reset script. The `cleanup_import_holder.php` is available if you need to purge old holders.
- If you want category-driven mapping (attach first 30 questions from a given category), we can add that — just say the target category.
