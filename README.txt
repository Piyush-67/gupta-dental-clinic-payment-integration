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
