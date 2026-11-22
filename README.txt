GUPTA DENTAL CLINIC — WEBSITE PACKAGE

PAGES
- index.html       Homepage
- about.html       New About page with clinic details
- services.html    Services and implant systems with visual cards
- contact-us.html  Patient enquiry form and contact details
- gupta-dental-clinic-logo.png Clinic logo and website icon
- clinic.js        Browser-side form handling
- clinic-config.js WhatsApp and local storage settings

CLINIC DETAILS USED
Gupta Dental Clinic – Aesthetic Smile & Implant Centre
NH114A, Professor Colony, New Barganda, Giridih, Jharkhand 815302
Phone / WhatsApp: +91 77399 57487
Reference: https://gupta-dental-clinic.grexa.site/

HOW TO TEST
1. Keep all files in the same folder.
2. Open the folder in VS Code.
3. Start Live Server from index.html (recommended; do not use file:// directly).
4. Open Contact and submit a TEST enquiry using fictitious details.

FORM LIMITATION
This frontend does not save or send submitted form details. The WhatsApp handoff opens a conversation with a generic enquiry message; details entered in the form are not transferred automatically.

PRIVACY / SAFETY
Do not enter real patient details, real addresses, medical details or actual clinic records in this frontend form. It does not save or send the entered details. For production, use a server-side backend with proper authentication, access control, encrypted transport, backups and retention policies.

FORM DECISIONS RETAINED
Required: full name, phone, complete address, age, reason for visit, consent.
Optional: alternate phone and email.
Removed: appointment date/time preferences, first-time/existing patient, symptoms, duration, conditions, medications, allergies, previous treatment and extra medical-history fields.
WhatsApp receives only the enquiry reference, not patient form details.
