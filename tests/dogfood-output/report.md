# QA / Dogfood Report

- Target: `http://localhost/RMUTP-SeniorProject/login.php`
- Date: 2026-08-26
- Scope: public catalog, authentication, role routing, admin/advisor/student navigation, API health, and database invariants
- Status: Completed

## Result

One high-severity authentication defect was found, fixed, and verified. No unresolved functional defects were found in the tested scope.

| Check | Result |
| --- | --- |
| PHP syntax (153 files) | Passed |
| JavaScript syntax (7 files) | Passed |
| HTTP smoke test | Passed |
| Backend table test | Passed |
| Data invariant test | Passed |
| Public catalog test | Passed |
| Admin core pages | Passed |
| Advisor core pages | Passed |
| Student core pages | Passed |
| Admin/advisor/student authentication API | Passed |

## Fixed finding

### ISSUE-001 - Authentication endpoint entered the administrator guard

- Severity: High
- Status: Fixed and verified
- Area: Authentication API
- Reproducibility before the fix: 3/3
- Evidence: `screenshots/issue-001-step-1.png`, `screenshots/issue-001-result.png`

The `api/auth/index.php` wrapper loaded the shared API without selecting the `auth` resource. Login requests therefore fell through to the administrator authorization guard and returned `Administrator access required.`

The wrapper now assigns `$_GET['resource'] = 'auth'` before loading `api/index.php`. Login and logout were retested successfully for administrator, advisor, and student roles.

## Browser coverage

- Public catalog: initial load, search empty state, pagination
- Administrator: dashboard, students, advisors, projects, documents, reports, settings, notifications
- Advisor: dashboard, students, proposal/draft/complete review, messages, notifications, calendar, profile, reports
- Student: dashboard, profile, project, proposal, draft, complete, barcode, timeline, documents, messages, notifications, status

Screenshots are stored in `tests/dogfood-output/screenshots/`.

## Database reset

The pre-reset database backup is:

`backups/rmutp_senior_project-before-qa-reset-20260826-171034.sql`

Operational data was cleared after testing. The table structure and default runtime settings were preserved.
