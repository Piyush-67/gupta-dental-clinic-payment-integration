GUPTA DENTAL CLINIC — WEBSITE AND PAYMENT INTEGRATION

PROJECT
- Static HTML/CSS/JavaScript website served by Apache.
- Core PHP payment backend in gupta-dental-payment-backend.
- MySQL database: gupta_dental_payments.
- Razorpay Checkout is integrated into contact-us.html.
- The enquiry fee is ₹5. Payment does not confirm an appointment.
- The clinic contacts the patient separately to coordinate an appointment.

LOCAL XAMPP SETUP
1. Place this folder at C:\xampp\htdocs\gupta-dental-clinic.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. In gupta-dental-payment-backend, run: composer install
4. Configure a local .env file in gupta-dental-payment-backend. Never commit it.
   Required variables:
   APP_ENV, APP_DEBUG, RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET,
   RAZORPAY_WEBHOOK_SECRET, DB_HOST, DB_NAME, DB_USER, DB_PASS
   Use Razorpay Test Mode credentials for local testing.
5. For a new, empty database only, create gupta_dental_payments and import
   gupta-dental-payment-backend/database/schema.sql.
6. For an existing database, back it up first and apply each unapplied
   migration in database/migrations in order. Do not import schema.sql over an
   existing database. The patient enquiry migration preserves existing payment
   rows and enquiry sequence values.
7. Open http://localhost/gupta-dental-clinic/contact-us.html through Apache.
   Do not use file:// or Live Server; PHP APIs must run through Apache.

PAYMENT AND ENQUIRY FLOW
- The contact form validates and submits the enquiry to the PHP backend.
- The backend validates the submitted values, records the enquiry, and reserves
  one local payment record before creating its Razorpay order.
- If an order-creation response is uncertain, retries check for the existing
  order by its stable receipt and never create another order for that enquiry.
  If Razorpay cannot confirm it, use the page's status check and contact the
  clinic rather than submitting a new enquiry.
- The browser opens Razorpay Checkout for the server-controlled ₹5 amount.
- Payment is successful only after server-side verification confirms capture.
- A unique GDC reference is assigned only after capture. Failed, cancelled,
  authorized-but-uncaptured, or unverified payments do not receive a reference.
- After capture, the contact page shows a confirmation card using enquiry and
  payment details returned by the backend. The patient can open an editable
  WhatsApp message to the clinic; opening WhatsApp does not confirm delivery.
- The contact page can resume an existing order and recover a confirmed
  payment after a refresh without storing patient details in browser storage.

WEBHOOKS
Razorpay cannot reach a localhost webhook URL. To test webhooks locally, use a
temporary, secured public tunnel and configure the resulting HTTPS callback in
Razorpay Test Mode. Do not expose a production webhook secret or use live keys
for local testing. Remove the tunnel when testing is complete.

DATABASE CARE
- Back up the existing database before applying migrations.
- Never reset auto-increment values or drop/recreate existing payment tables.
- Existing payment records remain unlinked to patient enquiries; do not invent
  or import patient details for historical or developer test payments.
- Patient enquiry records may include contact details and health-related
  information. Restrict database access and define an appropriate retention
  policy before production use.

PRIVACY
Submit only information needed to handle the enquiry. Do not enter real patient
information while testing; use fictitious details and Razorpay Test Mode.
Production deployment must use HTTPS and protect the database and application
logs. A ₹5 payment is an enquiry processing fee, not an appointment
confirmation or a diagnosis.

ADMIN DASHBOARD
- The admin dashboard is available at /admin/login.php after its additive
  migration has been reviewed and applied by an authorized database operator.
- Apply database/migrations/20261122_zz_admin_dashboard.sql after the existing
  patient-enquiry migration. It creates admin_users, admin_login_attempts,
  admin_audit_log, and enquiry_followups; it does not alter existing patient
  or payment rows.
  This implementation has not applied it to the clinic database; obtain
  separate owner approval before applying it there.
- No default administrator is seeded. Usernames are normalized to lowercase
  and must be 3-64 characters using letters, numbers, dots, underscores or
  hyphens. Create the first authorized account from an interactive CLI:
  C:\xampp\php\php.exe C:\xampp\htdocs\gupta-dental-clinic\gupta-dental-payment-backend\scripts\create-admin.php
- Passwords must be 12-72 bytes, are entered through a masked prompt, and are
  stored as PHP password hashes. The setup utility is CLI-only and refuses to
  create a second initial account.
- Manage credentials from an interactive CLI (replace placeholders with the
  account's normalized username):
  C:\xampp\php\php.exe C:\xampp\htdocs\gupta-dental-clinic\gupta-dental-payment-backend\scripts\manage-admin.php rename old-user new-user
  C:\xampp\php\php.exe C:\xampp\htdocs\gupta-dental-clinic\gupta-dental-payment-backend\scripts\manage-admin.php reset-password user-name
  C:\xampp\php\php.exe C:\xampp\htdocs\gupta-dental-clinic\gupta-dental-payment-backend\scripts\manage-admin.php activate user-name
  C:\xampp\php\php.exe C:\xampp\htdocs\gupta-dental-clinic\gupta-dental-payment-backend\scripts\manage-admin.php deactivate user-name
- Credential changes are written to admin_audit_log without recording
  passwords. Password resets and deactivation invalidate existing sessions;
  the last active administrator cannot be deactivated.
- Follow-up status is independent of payment lifecycle status. “Confirmed” in
  follow-up means the enquiry was marked for follow-up; it is not an
  appointment confirmation. Payments, payment attempts, and webhook events
  are read-only. The current webhook_events schema has no processing-error
  column, so the dashboard does not invent or display one.
- Refresh Data is manual; the dashboard does not poll in the background.
- Production administration must be served over HTTPS. Dashboard pages and
  APIs require an authenticated administrator session and CSRF token.

SAFE ADMIN TESTS
- Run the disposable-database integration suite with:
  C:\xampp\php\php.exe C:\xampp\htdocs\gupta-dental-clinic\gupta-dental-payment-backend\scripts\run-admin-tests.php
- The runner creates a uniquely named gdc_admin_dash_test_* database, imports
  the fresh-install schema and admin migration, runs the test suite, and drops
  only that generated database. It never uses DB_NAME and has no fallback to
  gupta_dental_payments. The configured database account must have permission
  to create and drop databases; otherwise the runner fails without running the
  tests. Never change the test database prefix or point tests at clinic data.

HOSTINGER DEPLOYMENT
- The public site and PHP backend use relative, same-origin API paths. Deploy
  them under the same HTTPS origin and preserve the
  gupta-dental-payment-backend/api/ path, or implement a separately reviewed
  origin/CSRF configuration before splitting the hosts. Do not enable wildcard
  CORS.
- Use PHP 8.2, install the locked Composer dependencies, keep .env outside
  version control, set APP_ENV=production and APP_DEBUG=false, and supply
  production Razorpay and database values through the hosting environment.
  Never place production credentials in HTML, JavaScript, or this repository.
- Back up the production database, verify its schema and migrations, obtain
  separate owner approval for the admin migration, and apply it once. Then run
  the CLI account-creation command over a trusted SSH terminal. No web-based
  credential setup is provided.
- Configure Razorpay's HTTPS webhook callback to the existing
  gupta-dental-payment-backend/api/webhook.php endpoint and set
  RAZORPAY_WEBHOOK_SECRET in the protected backend environment. Do not change
  webhook processing code as part of dashboard deployment.
- Disable PHP display_errors, enable protected server-side error logging,
  restrict the database account to the required application database, and
  verify Apache access rules are active. If the host ignores .htaccess, move
  non-public backend configuration, migrations, tests, and CLI utilities
  outside the document root before deployment.

WEBSITE PAGES
- index.html: Homepage
- about.html: Clinic information
- services.html: Services and implant systems
- contact-us.html: Patient enquiry and payment page
- clinic.js: Frontend form handling and payment integration
- clinic-config.js: Existing WhatsApp contact configuration for site links

DEPENDENCIES
PHP dependencies are managed by Composer using composer.json and composer.lock.
The vendor directory is generated locally by Composer and is not committed.
