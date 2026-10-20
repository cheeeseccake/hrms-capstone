# Audit Management installation notes

This feature is added to the existing `clinic` branch without replacing the existing `audit-trail.php` report center.

## Files added

- `modules/compliance/pages/audit-management.php` — authenticated management page, schedule form, checklist tracking, review, corrective actions, CSV report.
- `modules/compliance/public-audit-checklist.php` — external checklist endpoint. The unique token grants access only to one audit; no HRMS account is required.
- `modules/compliance/audit-evidence.php` — authenticated evidence download endpoint.
- `modules/compliance/database/audit-management-schema.sql` — additive database tables.
- `modules/compliance/classes/Page.php` — Audit Management added under Audit & Reporting.

## Deployment steps

1. Back up the production database and files before deployment.
2. Import `modules/compliance/database/audit-management-schema.sql` into the same MySQL/MariaDB database used by the HRMS. It creates only new `lc_audit_*` tables.
3. Deploy the new PHP files and the updated `classes/Page.php` to the matching production paths.
4. Confirm the web/PHP user can create a private directory at the account level outside `public_html`. Uploaded evidence is stored at `<account-root>/private_audit_evidence`, not in the public web directory. Verify directory permissions and backup policy.
5. Configure the server's PHP mail transport. Optionally set `HRMS_AUDIT_FROM_EMAIL` to a verified sender address. The page reports email send failure; PHP `mail()` returning true is not proof of final delivery, so test actual delivery and spam filtering.
6. Confirm the public base URL in `audit-management.php` and email links matches the production domain before sending real audit invitations.
7. Run syntax checks with the production PHP binary before opening the feature, for example:
   `/usr/local/lsws/lsphp82/bin/php -l modules/compliance/pages/audit-management.php`
   `/usr/local/lsws/lsphp82/bin/php -l modules/compliance/public-audit-checklist.php`
   `/usr/local/lsws/lsphp82/bin/php -l modules/compliance/audit-evidence.php`
   `/usr/local/lsws/lsphp82/bin/php -l modules/compliance/classes/Page.php`
8. Test with a non-sensitive sample audit: create, receive email, save progress, upload evidence, submit, review, return for correction, and approve.

## Security / scope notes

- External checklist links are bearer credentials. Share only with the intended responsible person. A resend generates a new token and invalidates the previous token.
- Tokens are stored as SHA-256 hashes in the database and expire 30 days after the due date.
- Public evidence uploads are limited to PDF/JPEG/PNG/WEBP, maximum 8 MB per file, checked by server-side MIME detection, and stored outside the web root.
- Evidence downloads require an authenticated HRMS session. Confirm that your existing `auth/session.php` enforces the expected authentication and that only authorized Compliance roles can open the management page and download evidence; add role-level authorization if the current app does not enforce it centrally.
- Audit templates are starting checklists only. Compliance personnel must tailor them to applicable requirements and verify actual evidence. A checklist response does not itself establish legal compliance.
- The implementation uses the project's existing `Database` class and assumes the production schema matches `database/db.php` conventions. It has not been executed against the live database or tested through the production mail server in this repository operation.
