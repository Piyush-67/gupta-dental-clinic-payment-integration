<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/gupta-dental-payment-backend/services/AdminAuth.php';
require_once dirname(__DIR__) . '/gupta-dental-payment-backend/services/Database.php';

AdminAuth::start();
AdminAuth::setSecurityHeaders();
$authenticated = false;
if (AdminAuth::hasAdminSession()) {
    try {
        $authenticated = AdminAuth::isAuthenticated(Database::connect());
    } catch (Throwable $e) {
        error_log('Administrator dashboard session validation failed.');
    }
}

if (!$authenticated) {
    header('Location: login.php', true, 303);
    exit;
}

$csrfToken = htmlspecialchars(AdminAuth::csrfToken(), ENT_QUOTES, 'UTF-8');
$adminUsername = htmlspecialchars(AdminAuth::adminUsername(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $csrfToken ?>">
  <meta name="theme-color" content="#082b52">
  <title>Clinic Admin | Gupta Dental Clinic</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/admin.css">
  <script src="assets/admin.js" defer></script>
</head>
<body class="dashboard-page">
  <div class="admin-layout">
    <aside class="admin-sidebar" id="adminSidebar">
      <a class="admin-brand" href="../index.html">
        <img src="../gupta-dental-clinic-logo.jpg" alt="">
        <span>Gupta Dental Clinic<small>Administration</small></span>
      </a>
      <nav class="admin-navigation" aria-label="Admin navigation">
        <button type="button" class="nav-item is-active" data-view="dashboard" aria-current="page">
          <span class="nav-icon" aria-hidden="true">▦</span>Dashboard
        </button>
        <button type="button" class="nav-item" data-view="enquiries">
          <span class="nav-icon" aria-hidden="true">◎</span>Patient Enquiries
        </button>
        <button type="button" class="nav-item" data-view="payments">
          <span class="nav-icon" aria-hidden="true">₹</span>Payments
        </button>
        <button type="button" class="nav-item" data-view="attempts">
          <span class="nav-icon" aria-hidden="true">↻</span>Payment Attempts
        </button>
        <button type="button" class="nav-item" data-view="webhooks">
          <span class="nav-icon" aria-hidden="true">↗</span>Webhook Events
        </button>
      </nav>
      <div class="sidebar-bottom">
        <span class="sidebar-note">Private clinic records</span>
        <button type="button" class="nav-item nav-logout" data-logout>
          <span class="nav-icon" aria-hidden="true">↪</span>Sign out
        </button>
      </div>
    </aside>

    <div class="admin-main">
      <header class="admin-topbar">
        <button type="button" class="mobile-menu-button" id="mobileMenuButton" aria-label="Open navigation" aria-expanded="false">☰</button>
        <div class="topbar-title">Clinic administration</div>
        <div class="refresh-controls">
          <div class="refresh-status"><span>Last refreshed</span><time id="lastRefresh">Not yet</time><span id="refreshMessage" role="status" aria-live="polite"></span></div>
          <button type="button" class="button button-primary refresh-button" id="refreshData">Refresh Data</button>
        </div>
        <div class="admin-account"><span class="account-dot" aria-hidden="true"></span><span><?= $adminUsername ?></span></div>
      </header>

      <main class="admin-content">
        <section class="content-view is-visible" id="view-dashboard" data-panel="dashboard">
          <div class="page-heading">
            <div><p class="eyebrow">Overview</p><h1>Dashboard</h1></div>
            <p class="page-description">A current snapshot of enquiries and payment records.</p>
          </div>
          <p class="view-message" id="overviewMessage" role="status" aria-live="polite"></p>
          <div class="stat-grid" id="overviewCards">
            <article class="stat-card"><span>Total Patient Enquiries</span><strong data-stat="total_enquiries">—</strong></article>
            <article class="stat-card"><span>New Enquiries</span><strong data-stat="new_enquiries">—</strong></article>
            <article class="stat-card"><span>Pending Payments</span><strong data-stat="pending_payments">—</strong></article>
            <article class="stat-card"><span>Successful Payments</span><strong data-stat="successful_payments">—</strong></article>
            <article class="stat-card"><span>Failed Payment Attempts</span><strong data-stat="failed_payment_attempts">—</strong></article>
            <article class="stat-card stat-card-highlight"><span>Total Captured Amount</span><strong data-stat="captured_amount">—</strong><small>Captured payments only</small></article>
          </div>
          <div class="panel">
            <div class="panel-heading"><div><p class="eyebrow">Latest records</p><h2>Recent enquiries</h2></div><button type="button" class="text-button" data-open-view="enquiries">View all enquiries</button></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Patient</th><th>GDC reference</th><th>Enquiry date</th><th>Follow-up</th><th>Payment</th></tr></thead>
                <tbody id="recentEnquiries"><tr><td colspan="5" class="table-message">Loading enquiries…</td></tr></tbody>
              </table>
            </div>
          </div>
          <p class="notice">A follow-up marked “Confirmed” is not an appointment confirmation. Payment status is shown separately.</p>
        </section>

        <section class="content-view" id="view-enquiries" data-panel="enquiries" hidden>
          <div class="page-heading"><div><p class="eyebrow">Patient records</p><h1>Patient Enquiries</h1></div><p class="page-description">Review enquiry details and manage follow-up status.</p></div>
          <p class="notice">Follow-up status does not change payment status or confirm an appointment.</p>
          <form class="filter-panel" id="enquiryFilters">
            <label class="filter-search">Search<input name="search" type="search" maxlength="120" placeholder="Name, phone, email or GDC reference"></label>
            <label>Follow-up status<select name="status"><option value="all">All statuses</option><option>New</option><option>Contacted</option><option>Confirmed</option><option>Completed</option><option>Cancelled</option></select></label>
            <label>Payment status<select name="payment_status" id="enquiryPaymentStatusFilter"><option value="all">All statuses</option></select></label>
            <label>From<input name="from" type="date"></label>
            <label>To<input name="to" type="date"></label>
            <label>Sort<select name="sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></label>
            <button type="submit" class="button button-primary">Apply filters</button>
          </form>
          <p class="view-message" id="enquiriesMessage" role="status" aria-live="polite"></p>
          <div class="panel table-panel">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Enquiry ID</th><th>Patient</th><th>Phone</th><th>Email</th><th>GDC reference</th><th>Enquiry details</th><th>Enquiry date</th><th>Enquiry status</th><th>Follow-up</th><th>Payment</th><th></th></tr></thead>
                <tbody id="enquiriesTable"><tr><td colspan="11" class="table-message">Select Patient Enquiries to load records.</td></tr></tbody>
              </table>
            </div>
            <div class="pagination" id="enquiriesPagination"></div>
          </div>
        </section>

        <section class="content-view" id="view-attempts" data-panel="attempts" hidden>
          <div class="page-heading"><div><p class="eyebrow">Payment history</p><h1>Payment Attempts</h1></div><p class="page-description">Individual attempts are separate from the parent payment record.</p></div>
          <form class="filter-panel" id="attemptFilters">
            <label class="filter-search">Search<input name="search" type="search" maxlength="120" placeholder="Attempt, payment, patient or GDC reference"></label>
            <label>Attempt status<select name="status" id="attemptStatusFilter"><option value="all">All statuses</option></select></label>
            <label>From<input name="from" type="date"></label>
            <label>To<input name="to" type="date"></label>
            <button type="submit" class="button button-primary">Apply filters</button>
          </form>
          <p class="view-message" id="attemptsMessage" role="status" aria-live="polite"></p>
          <div class="panel table-panel">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Attempt ID</th><th>Patient / enquiry</th><th>GDC reference</th><th>Order ID</th><th>Razorpay payment ID</th><th>Status</th><th>Failure reason</th><th>Created</th><th></th></tr></thead>
                <tbody id="attemptsTable"><tr><td colspan="9" class="table-message">Select Payment Attempts to load records.</td></tr></tbody>
              </table>
            </div>
            <div class="pagination" id="attemptsPagination"></div>
          </div>
        </section>

        <section class="content-view" id="view-payments" data-panel="payments" hidden>
          <div class="page-heading"><div><p class="eyebrow">Read-only monitoring</p><h1>Payments</h1></div><p class="page-description">Payment records and their related attempts.</p></div>
          <form class="filter-panel" id="paymentFilters">
            <label class="filter-search">Search<input name="search" type="search" maxlength="120" placeholder="GDC reference, order ID or payment ID"></label>
            <label>Payment status<select name="status" id="paymentStatusFilter"><option value="all">All statuses</option></select></label>
            <label>From<input name="from" type="date"></label>
            <label>To<input name="to" type="date"></label>
            <button type="submit" class="button button-primary">Apply filters</button>
          </form>
          <p class="view-message" id="paymentsMessage" role="status" aria-live="polite"></p>
          <div class="panel table-panel">
            <div class="table-wrap">
              <table>
                <thead><tr><th>GDC reference</th><th>Patient / enquiry</th><th>Order ID</th><th>Payment ID</th><th>Amount</th><th>Status</th><th>Signature</th><th>Created</th><th></th></tr></thead>
                <tbody id="paymentsTable"><tr><td colspan="9" class="table-message">Select Payments to load records.</td></tr></tbody>
              </table>
            </div>
            <div class="pagination" id="paymentsPagination"></div>
          </div>
        </section>

        <section class="content-view" id="view-webhooks" data-panel="webhooks" hidden>
          <div class="page-heading"><div><p class="eyebrow">Read-only monitoring</p><h1>Webhook Events</h1></div><p class="page-description">Inspect recorded event metadata and linked payment references.</p></div>
          <form class="filter-panel" id="webhookFilters">
            <label class="filter-search">Search<input name="search" type="search" maxlength="120" placeholder="Event, type, order or payment ID"></label>
            <label>Processing status<select name="status" id="webhookStatusFilter"><option value="all">All statuses</option></select></label>
            <label>From<input name="from" type="date"></label>
            <label>To<input name="to" type="date"></label>
            <button type="submit" class="button button-primary">Apply filters</button>
          </form>
          <p class="view-message" id="webhooksMessage" role="status" aria-live="polite"></p>
          <div class="panel table-panel">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Event ID</th><th>Event type</th><th>Processing</th><th>Received</th><th>Payment reference</th><th></th></tr></thead>
                <tbody id="webhooksTable"><tr><td colspan="6" class="table-message">Select Webhook Events to load records.</td></tr></tbody>
              </table>
            </div>
            <div class="pagination" id="webhooksPagination"></div>
          </div>
        </section>
      </main>
    </div>
  </div>

  <dialog class="detail-dialog" id="detailDialog" aria-labelledby="detailTitle">
    <div class="dialog-heading"><div><p class="eyebrow">Record details</p><h2 id="detailTitle">Details</h2></div><button type="button" class="dialog-close" id="closeDialog" aria-label="Close details">×</button></div>
    <div id="detailContent"></div>
  </dialog>
</body>
</html>
