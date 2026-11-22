(() => {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const loginForm = document.getElementById('adminLoginForm');
  const authEndpoint = '../gupta-dental-payment-backend/api/admin/auth.php';

  async function readResponse(response) {
    let result;
    try {
      result = await response.json();
    } catch (error) {
      throw new Error('The server returned an unreadable response.');
    }

    if (!response.ok || !result.success) {
      if (response.status === 401 && document.body.classList.contains('dashboard-page')) {
        window.location.href = 'login.php';
      }
      throw new Error(result.message || 'The request could not be completed.');
    }

    return result.data;
  }

  if (loginForm) {
    loginForm.addEventListener('submit', async event => {
      event.preventDefault();
      const message = document.getElementById('loginMessage');
      const button = loginForm.querySelector('button[type="submit"]');
      const username = document.getElementById('adminUsername').value.trim();
      const password = document.getElementById('adminPassword').value;
      message.textContent = '';
      message.classList.remove('is-error');

      if (!loginForm.reportValidity()) return;

      button.disabled = true;
      button.textContent = 'Signing in…';
      try {
        await readResponse(await fetch(`${authEndpoint}?action=login`, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ username, password })
        }));
        window.location.href = 'index.php';
      } catch (error) {
        message.textContent = error.message;
        message.classList.add('is-error');
        button.disabled = false;
        button.textContent = 'Sign in';
      }
    });
    return;
  }

  if (!document.body.classList.contains('dashboard-page')) return;

  const dataEndpoint = '../gupta-dental-payment-backend/api/admin/data.php';
  const updateEndpoint = '../gupta-dental-payment-backend/api/admin/update-followup.php';
  const state = {
    enquiriesPage: 1,
    paymentsPage: 1,
    attemptsPage: 1,
    webhooksPage: 1
  };
  const loadedViews = new Set();
  const dateTimeFormatter = new Intl.DateTimeFormat('en-IN', {
    dateStyle: 'medium',
    timeStyle: 'medium',
    timeZone: 'Asia/Kolkata'
  });
  const refreshTimeFormatter = new Intl.DateTimeFormat('en-IN', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Kolkata'
  });
  const detailDialog = document.getElementById('detailDialog');
  const detailContent = document.getElementById('detailContent');
  const refreshButton = document.getElementById('refreshData');
  const lastRefresh = document.getElementById('lastRefresh');
  const refreshMessage = document.getElementById('refreshMessage');
  let refreshInProgress = false;

  function setMessage(id, text, isError = false) {
    const element = document.getElementById(id);
    if (!element) return;
    element.textContent = text;
    element.classList.toggle('is-error', isError);
  }

  function markRefreshSuccess() {
    lastRefresh.textContent = refreshTimeFormatter.format(new Date());
    refreshMessage.textContent = '';
    refreshMessage.classList.remove('is-error');
  }

  async function getData(action, filters = {}) {
    const response = await fetch(`${dataEndpoint}?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(filters)
    });
    return readResponse(response);
  }

  async function postData(url, values) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(values)
    });
    return readResponse(response);
  }

  function text(value, fallback = '—') {
    if (value === null || value === undefined || value === '') return fallback;
    return String(value);
  }

  function dateTime(value) {
    if (!value) return '—';
    const raw = String(value);
    const date = new Date(
      /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)
        ? `${raw.replace(' ', 'T')}+05:30`
        : raw
    );
    if (Number.isNaN(date.getTime())) return String(value);
    return dateTimeFormatter.format(date);
  }

  function money(amountPaise, currency = 'INR') {
    const amount = Number(amountPaise);
    if (!Number.isFinite(amount)) return '—';
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency
    }).format(amount / 100);
  }

  function cell(value, className = '') {
    const element = document.createElement('td');
    if (className) element.className = className;
    element.textContent = text(value);
    return element;
  }

  function badge(value) {
    const element = document.createElement('span');
    element.className = 'status-badge';
    const normalized = String(value || '').toLowerCase();
    if (normalized === 'captured' || normalized === 'processed' || normalized === 'completed') {
      element.classList.add('is-good');
    } else if (normalized === 'failed' || normalized === 'cancelled') {
      element.classList.add('is-bad');
    } else if (normalized === 'created' || normalized === 'authorized' || normalized === 'new') {
      element.classList.add('is-waiting');
    }
    element.textContent = text(value);
    return element;
  }

  function actionButton(label, action, id) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'row-action';
    button.textContent = label;
    button.addEventListener('click', async () => {
      try {
        await action(id);
      } catch (error) {
        const activeView = document.querySelector('[data-panel]:not([hidden])')?.dataset.panel;
        const messageId = {
          dashboard: 'overviewMessage',
          enquiries: 'enquiriesMessage',
          payments: 'paymentsMessage',
          attempts: 'attemptsMessage',
          webhooks: 'webhooksMessage'
        }[activeView] || 'overviewMessage';
        setMessage(messageId, error.message, true);
      }
    });
    return button;
  }

  function showEmpty(tbody, columns, message) {
    const row = document.createElement('tr');
    const empty = document.createElement('td');
    empty.colSpan = columns;
    empty.className = 'table-message';
    empty.textContent = message;
    row.append(empty);
    tbody.replaceChildren(row);
  }

  function appendPagination(containerId, result, onPage) {
    const container = document.getElementById(containerId);
    container.replaceChildren();
    if (result.total === 0) {
      container.textContent = '0 records';
      return;
    }

    const summary = document.createElement('span');
    summary.textContent = `Page ${result.page} of ${result.pages} · ${result.total} records`;
    const previous = document.createElement('button');
    previous.type = 'button';
    previous.textContent = 'Previous';
    previous.disabled = result.page <= 1;
    previous.addEventListener('click', () => onPage(result.page - 1));
    const next = document.createElement('button');
    next.type = 'button';
    next.textContent = 'Next';
    next.disabled = result.page >= result.pages;
    next.addEventListener('click', () => onPage(result.page + 1));
    container.append(previous, summary, next);
  }

  function detailsGrid(fields) {
    const grid = document.createElement('dl');
    grid.className = 'detail-grid';
    fields.forEach(([label, value, wide = false]) => {
      const wrapper = document.createElement('div');
      wrapper.className = wide ? 'detail-field wide' : 'detail-field';
      const heading = document.createElement('dt');
      heading.textContent = label;
      const detail = document.createElement('dd');
      detail.textContent = text(value);
      wrapper.append(heading, detail);
      grid.append(wrapper);
    });
    return grid;
  }

  function openDetails(title, content) {
    document.getElementById('detailTitle').textContent = text(title, 'Record details');
    detailContent.replaceChildren(content);
    detailDialog.showModal();
  }

  function appendSectionTitle(container, title) {
    const heading = document.createElement('h3');
    heading.className = 'detail-section-title';
    heading.textContent = title;
    container.append(heading);
  }

  function attemptDetails(attempt) {
    return detailsGrid([
      ['Attempt ID', attempt.id],
      ['Razorpay payment ID', attempt.razorpay_payment_id, true],
      ['Attempt status', attempt.payment_status],
      ['Amount', money(attempt.amount_paise, attempt.currency)],
      ['Currency', attempt.currency],
      ['Method', attempt.method],
      ['Error code', attempt.error_code],
      ['Failure reason', attempt.error_description, true],
      ['Created', dateTime(attempt.created_at)],
      ['Updated', dateTime(attempt.updated_at)],
      ['Verified', dateTime(attempt.verified_at)]
    ]);
  }

  async function showEnquiry(id) {
    const detail = await getData('enquiry', { id });
    const content = document.createElement('div');
    content.className = 'detail-body';
    content.append(detailsGrid([
      ['Enquiry ID', detail.id],
      ['Patient name', detail.full_name],
      ['Primary phone', detail.primary_phone],
      ['Alternate phone', detail.alternate_phone],
      ['Email', detail.email],
      ['Age', detail.age],
      ['Reason for visit', detail.reason_for_visit],
      ['Complete address', detail.complete_address, true],
      ['GDC reference', detail.enquiry_reference],
      ['Payment status', detail.payment_status],
      ['Enquiry processing status', detail.enquiry_status],
      ['Enquiry date', dateTime(detail.created_at)],
      ['Consent recorded', detail.consent_given ? dateTime(detail.consented_at) : 'No'],
      ['Follow-up last updated', dateTime(detail.follow_up_updated_at)],
      ['Updated by', detail.follow_up_updated_by],
      ['Payment record ID', detail.payment_record_id],
      ['Payment amount', detail.amount_paise === null ? null : money(detail.amount_paise, detail.currency)],
      ['Payment currency', detail.currency],
      ['Razorpay order ID', detail.razorpay_order_id, true],
      ['Razorpay payment ID', detail.razorpay_payment_id, true],
      ['Signature verified', Number(detail.signature_verified) === 1 ? 'Yes' : 'No'],
      ['Payment created', dateTime(detail.payment_created_at)],
      ['Payment updated', dateTime(detail.payment_updated_at)],
      ['Payment verified', dateTime(detail.payment_verified_at)]
    ]));

    appendSectionTitle(content, `Related payment attempts (${detail.attempts.length})`);
    if (detail.attempts.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'table-message';
      empty.textContent = 'No payment attempts are recorded for this enquiry.';
      content.append(empty);
    } else {
      detail.attempts.forEach(attempt => {
        const section = document.createElement('section');
        section.className = 'attempt-detail';
        section.append(attemptDetails(attempt));
        content.append(section);
      });
    }

    appendSectionTitle(content, 'Enquiry follow-up');
    const note = document.createElement('p');
    note.className = 'notice';
    note.textContent = 'This status tracks clinic follow-up only; “Confirmed” does not mean an appointment is booked and never changes payment status.';
    content.append(note);
    appendSectionTitle(content, 'Follow-up status history');
    if (detail.follow_up_history.length === 0) {
      const emptyHistory = document.createElement('p');
      emptyHistory.className = 'table-message';
      emptyHistory.textContent = 'No follow-up status changes have been recorded.';
      content.append(emptyHistory);
    } else {
      const history = document.createElement('ul');
      history.className = 'follow-up-history';
      detail.follow_up_history.forEach(change => {
        const entry = document.createElement('li');
        entry.textContent = `${change.previous_status} → ${change.new_status} · ${text(change.changed_by, 'Administrator unavailable')} · ${dateTime(change.changed_at)}`;
        history.append(entry);
      });
      content.append(history);
    }

    const actions = document.createElement('div');
    actions.className = 'detail-actions';
    const label = document.createElement('label');
    label.textContent = 'Follow-up status';
    const select = document.createElement('select');
    ['New', 'Contacted', 'Confirmed', 'Completed', 'Cancelled'].forEach(status => {
      const option = document.createElement('option');
      option.value = status;
      option.textContent = status;
      option.selected = status === (detail.follow_up_status || 'New');
      select.append(option);
    });
    label.append(select);
    const save = document.createElement('button');
    save.type = 'button';
    save.className = 'button button-primary';
    save.textContent = 'Save follow-up';
    save.addEventListener('click', async () => {
      if (select.value === (detail.follow_up_status || 'New')) return;
      if (!window.confirm(`Change follow-up status to “${select.value}”? This does not confirm an appointment.`)) return;
      save.disabled = true;
      try {
        await postData(updateEndpoint, { id, status: select.value });
        detail.follow_up_status = select.value;
        detail.follow_up_updated_at = new Date().toISOString();
        detail.follow_up_updated_by = document.querySelector('.admin-account span:last-child')?.textContent || '';
        setMessage('enquiriesMessage', 'Follow-up status updated.');
        if (loadedViews.has('enquiries')) await loadEnquiries(state.enquiriesPage);
        await loadOverview();
        detailDialog.close();
      } catch (error) {
        setMessage('enquiriesMessage', error.message, true);
        save.disabled = false;
      }
    });
    actions.append(label, save);
    content.append(actions);
    openDetails(detail.full_name, content);
  }

  async function showPayment(id) {
    const detail = await getData('payment', { id });
    const content = document.createElement('div');
    content.className = 'detail-body';
    content.append(detailsGrid([
      ['GDC reference', detail.enquiry_reference],
      ['Patient / enquiry', detail.patient_name],
      ['Patient phone', detail.primary_phone],
      ['Patient email', detail.email],
      ['Enquiry record ID', detail.patient_enquiry_id],
      ['Razorpay order ID', detail.razorpay_order_id, true],
      ['Razorpay payment ID', detail.razorpay_payment_id, true],
      ['Amount', money(detail.amount_paise, detail.currency)],
      ['Currency', detail.currency],
      ['Payment status', detail.payment_status],
      ['Order creation status', detail.order_creation_status],
      ['Signature verified', Number(detail.signature_verified) === 1 ? 'Yes' : 'No'],
      ['Created', dateTime(detail.created_at)],
      ['Updated', dateTime(detail.updated_at)],
      ['Verified', dateTime(detail.verified_at)]
    ]));

    appendSectionTitle(content, `Payment attempts (${detail.attempts.length})`);
    if (detail.attempts.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'table-message';
      empty.textContent = 'No payment attempts are recorded for this payment.';
      content.append(empty);
    } else {
      detail.attempts.forEach(attempt => {
        const section = document.createElement('section');
        section.className = 'attempt-detail';
        section.append(attemptDetails(attempt));
        content.append(section);
      });
    }
    openDetails(detail.enquiry_reference || detail.razorpay_order_id || `Payment ${detail.id}`, content);
  }

  async function showAttempt(id) {
    const detail = await getData('attempt', { id });
    const content = document.createElement('div');
    content.className = 'detail-body';
    content.append(detailsGrid([
      ['Attempt ID', detail.id],
      ['Patient / enquiry', detail.patient_name || detail.patient_enquiry_id],
      ['Patient phone', detail.primary_phone],
      ['Patient email', detail.email],
      ['Enquiry reference', detail.enquiry_reference],
      ['Parent payment record ID', detail.payment_record_id],
      ['Parent payment status', detail.parent_payment_status],
      ['Razorpay order ID', detail.razorpay_order_id, true],
      ['Razorpay payment ID', detail.razorpay_payment_id, true],
      ['Attempt status', detail.payment_status],
      ['Amount', money(detail.amount_paise, detail.currency)],
      ['Currency', detail.currency],
      ['Method', detail.method],
      ['Error code', detail.error_code],
      ['Failure reason', detail.error_description, true],
      ['Created', dateTime(detail.created_at)],
      ['Updated', dateTime(detail.updated_at)],
      ['Verified', dateTime(detail.verified_at)]
    ]));
    openDetails(`Payment attempt ${detail.id}`, content);
  }

  async function showWebhook(id) {
    const detail = await getData('webhook', { id });
    const content = document.createElement('div');
    content.className = 'detail-body';
    content.append(detailsGrid([
      ['Event ID', detail.razorpay_event_id, true],
      ['Event type', detail.event_type],
      ['Processing status', detail.processing_status],
      ['Received', dateTime(detail.received_at)],
      ['Processed', dateTime(detail.processed_at)],
      ['Related GDC reference', detail.enquiry_reference],
      ['Razorpay order ID', detail.order_id, true],
      ['Razorpay payment ID', detail.payment_id, true]
    ]));
    const note = document.createElement('p');
    note.className = 'notice';
    note.textContent = 'Only metadata stored by the application is shown. Webhook payloads, request headers and secrets are not exposed.';
    content.append(note);
    openDetails(detail.event_type || 'Webhook event', content);
  }

  async function loadOverview() {
    const tbody = document.getElementById('recentEnquiries');
    if (!loadedViews.has('dashboard')) {
      showEmpty(tbody, 5, 'Loading enquiries…');
    }

    try {
      const [stats, result] = await Promise.all([
        getData('overview'),
        getData('enquiries', { page: 1, per_page: 10, sort: 'newest' })
      ]);
      document.querySelector('[data-stat="total_enquiries"]').textContent = stats.total_enquiries;
      document.querySelector('[data-stat="new_enquiries"]').textContent = stats.new_enquiries;
      document.querySelector('[data-stat="pending_payments"]').textContent = stats.pending_payments;
      document.querySelector('[data-stat="successful_payments"]').textContent = stats.successful_payments;
      document.querySelector('[data-stat="failed_payment_attempts"]').textContent = stats.failed_payment_attempts;
      document.querySelector('[data-stat="captured_amount"]').textContent = money(stats.captured_amount_paise);
      renderRecentEnquiries(result.items);
      loadedViews.add('dashboard');
      setMessage('overviewMessage', '');
      if (!refreshInProgress) markRefreshSuccess();
      return true;
    } catch (error) {
      if (!loadedViews.has('dashboard')) {
        showEmpty(tbody, 5, 'Dashboard data could not be loaded.');
      }
      setMessage('overviewMessage', error.message, true);
      return false;
    }
  }

  function renderRecentEnquiries(items) {
    const tbody = document.getElementById('recentEnquiries');
    if (!items.length) {
      showEmpty(tbody, 5, 'No patient enquiries are available.');
      return;
    }
    const rows = items.map(item => {
      const row = document.createElement('tr');
      const patient = document.createElement('td');
      const name = document.createElement('strong');
      name.textContent = text(item.full_name);
      const phone = document.createElement('span');
      phone.className = 'table-subtext';
      phone.textContent = text(item.primary_phone);
      patient.append(name, phone);
      row.append(patient, cell(item.enquiry_reference), cell(dateTime(item.created_at)));
      const followUp = document.createElement('td');
      followUp.append(badge(item.follow_up_status));
      row.append(followUp);
      const payment = document.createElement('td');
      payment.append(badge(item.payment_status));
      row.append(payment);
      return row;
    });
    tbody.replaceChildren(...rows);
  }

  function formFilters(form, extra = {}) {
    const values = Object.fromEntries(new FormData(form).entries());
    return { ...values, ...extra };
  }

  async function loadEnquiries(page = 1) {
    state.enquiriesPage = page;
    setMessage('enquiriesMessage', '');
    const tbody = document.getElementById('enquiriesTable');
    if (!loadedViews.has('enquiries')) {
      showEmpty(tbody, 11, 'Loading patient enquiries…');
    }
    try {
      const result = await getData('enquiries', formFilters(
        document.getElementById('enquiryFilters'),
        { page, per_page: 25 }
      ));
      setStatusOptions('enquiryPaymentStatusFilter', result.payment_statuses);
      if (!result.items.length) {
        showEmpty(tbody, 11, 'No patient enquiries match these filters.');
      } else {
        tbody.replaceChildren(...result.items.map(item => {
          const row = document.createElement('tr');
          row.append(cell(item.id));
          const patient = document.createElement('td');
          patient.textContent = text(item.full_name);
          row.append(
            patient,
            cell(item.primary_phone),
            cell(item.email),
            cell(item.enquiry_reference),
            cell(item.reason_for_visit),
            cell(dateTime(item.created_at)),
            cell(item.enquiry_status)
          );
          const followUp = document.createElement('td');
          followUp.append(badge(item.follow_up_status));
          row.append(followUp);
          const payment = document.createElement('td');
          payment.append(badge(item.payment_status));
          row.append(payment);
          const action = document.createElement('td');
          action.append(actionButton('Details', showEnquiry, item.id));
          row.append(action);
          return row;
        }));
      }
      appendPagination('enquiriesPagination', result, loadEnquiries);
      setMessage('enquiriesMessage', `${result.total} enquiry record${result.total === 1 ? '' : 's'} found.`);
      loadedViews.add('enquiries');
      if (!refreshInProgress) markRefreshSuccess();
      return true;
    } catch (error) {
      if (!loadedViews.has('enquiries')) {
        showEmpty(tbody, 11, 'Enquiries could not be loaded.');
      }
      setMessage('enquiriesMessage', error.message, true);
      return false;
    }
  }

  function setStatusOptions(selectId, values) {
    const select = document.getElementById(selectId);
    const current = select.value;
    select.replaceChildren(new Option('All statuses', 'all'));
    values.forEach(value => select.add(new Option(value, value)));
    if (values.includes(current)) select.value = current;
  }

  async function loadPayments(page = 1) {
    state.paymentsPage = page;
    setMessage('paymentsMessage', '');
    const tbody = document.getElementById('paymentsTable');
    if (!loadedViews.has('payments')) {
      showEmpty(tbody, 9, 'Loading payment records…');
    }
    try {
      const result = await getData('payments', formFilters(
        document.getElementById('paymentFilters'),
        { page, per_page: 25 }
      ));
      setStatusOptions('paymentStatusFilter', result.statuses);
      if (!result.items.length) {
        showEmpty(tbody, 9, 'No payment records match these filters.');
      } else {
        tbody.replaceChildren(...result.items.map(item => {
          const row = document.createElement('tr');
          row.append(cell(item.enquiry_reference));
          const patient = document.createElement('td');
          const patientName = document.createElement('strong');
          patientName.textContent = text(
            item.patient_name || (item.patient_enquiry_id ? `Enquiry ${item.patient_enquiry_id}` : 'Unlinked payment')
          );
          patient.append(patientName);
          if (item.primary_phone) {
            const phone = document.createElement('span');
            phone.className = 'table-subtext';
            phone.textContent = text(item.primary_phone);
            patient.append(phone);
          }
          if (item.email) {
            const email = document.createElement('span');
            email.className = 'table-subtext';
            email.textContent = text(item.email);
            patient.append(email);
          }
          row.append(patient, cell(item.razorpay_order_id), cell(item.razorpay_payment_id), cell(money(item.amount_paise, item.currency)));
          const status = document.createElement('td');
          status.append(badge(item.payment_status));
          row.append(status);
          row.append(cell(Number(item.signature_verified) === 1 ? 'Verified' : 'Not verified'));
          row.append(cell(dateTime(item.created_at)));
          const action = document.createElement('td');
          action.append(actionButton('Details', showPayment, item.id));
          row.append(action);
          return row;
        }));
      }
      appendPagination('paymentsPagination', result, loadPayments);
      setMessage('paymentsMessage', `${result.total} payment record${result.total === 1 ? '' : 's'} found.`);
      loadedViews.add('payments');
      if (!refreshInProgress) markRefreshSuccess();
      return true;
    } catch (error) {
      if (!loadedViews.has('payments')) {
        showEmpty(tbody, 9, 'Payments could not be loaded.');
      }
      setMessage('paymentsMessage', error.message, true);
      return false;
    }
  }

  async function loadAttempts(page = 1) {
    state.attemptsPage = page;
    setMessage('attemptsMessage', '');
    const tbody = document.getElementById('attemptsTable');
    if (!loadedViews.has('attempts')) {
      showEmpty(tbody, 9, 'Loading payment attempts…');
    }

    try {
      const result = await getData('attempts', formFilters(
        document.getElementById('attemptFilters'),
        { page, per_page: 25 }
      ));
      setStatusOptions('attemptStatusFilter', result.statuses);
      if (!result.items.length) {
        showEmpty(tbody, 9, 'No payment attempts match these filters.');
      } else {
        tbody.replaceChildren(...result.items.map(item => {
          const row = document.createElement('tr');
          row.append(cell(item.id));
          const patient = document.createElement('td');
          const name = document.createElement('strong');
          name.textContent = text(item.patient_name || (item.patient_enquiry_id ? `Enquiry ${item.patient_enquiry_id}` : 'Unlinked payment'));
          patient.append(name);
          if (item.primary_phone) {
            const phone = document.createElement('span');
            phone.className = 'table-subtext';
            phone.textContent = text(item.primary_phone);
            patient.append(phone);
          }
          row.append(
            patient,
            cell(item.enquiry_reference),
            cell(item.razorpay_order_id),
            cell(item.razorpay_payment_id)
          );
          const status = document.createElement('td');
          status.append(badge(item.payment_status));
          row.append(status, cell(item.error_description), cell(dateTime(item.created_at)));
          const action = document.createElement('td');
          action.append(actionButton('Details', showAttempt, item.id));
          row.append(action);
          return row;
        }));
      }
      appendPagination('attemptsPagination', result, loadAttempts);
      setMessage('attemptsMessage', `${result.total} payment attempt${result.total === 1 ? '' : 's'} found.`);
      loadedViews.add('attempts');
      if (!refreshInProgress) markRefreshSuccess();
      return true;
    } catch (error) {
      if (!loadedViews.has('attempts')) {
        showEmpty(tbody, 9, 'Payment attempts could not be loaded.');
      }
      setMessage('attemptsMessage', error.message, true);
      return false;
    }
  }

  async function loadWebhooks(page = 1) {
    state.webhooksPage = page;
    setMessage('webhooksMessage', '');
    const tbody = document.getElementById('webhooksTable');
    if (!loadedViews.has('webhooks')) {
      showEmpty(tbody, 6, 'Loading webhook events…');
    }
    try {
      const result = await getData('webhooks', formFilters(
        document.getElementById('webhookFilters'),
        { page, per_page: 25 }
      ));
      setStatusOptions('webhookStatusFilter', result.statuses);
      if (!result.items.length) {
        showEmpty(tbody, 6, 'No webhook events match these filters.');
      } else {
        tbody.replaceChildren(...result.items.map(item => {
          const row = document.createElement('tr');
          row.append(cell(item.razorpay_event_id), cell(item.event_type));
          const status = document.createElement('td');
          status.append(badge(item.processing_status));
          row.append(status, cell(dateTime(item.received_at)), cell(item.enquiry_reference));
          const action = document.createElement('td');
          action.append(actionButton('Details', showWebhook, item.id));
          row.append(action);
          return row;
        }));
      }
      appendPagination('webhooksPagination', result, loadWebhooks);
      setMessage('webhooksMessage', `${result.total} webhook event${result.total === 1 ? '' : 's'} found.`);
      loadedViews.add('webhooks');
      if (!refreshInProgress) markRefreshSuccess();
      return true;
    } catch (error) {
      if (!loadedViews.has('webhooks')) {
        showEmpty(tbody, 6, 'Webhook events could not be loaded.');
      }
      setMessage('webhooksMessage', error.message, true);
      return false;
    }
  }

  async function showView(name) {
    document.querySelectorAll('[data-panel]').forEach(panel => {
      const active = panel.dataset.panel === name;
      panel.hidden = !active;
      panel.classList.toggle('is-visible', active);
    });
    document.querySelectorAll('.nav-item[data-view]').forEach(button => {
      const active = button.dataset.view === name;
      button.classList.toggle('is-active', active);
      if (active) button.setAttribute('aria-current', 'page');
      else button.removeAttribute('aria-current');
    });
    document.body.classList.remove('sidebar-open');
    document.getElementById('mobileMenuButton').setAttribute('aria-expanded', 'false');

    if (name === 'dashboard') await loadOverview();
    if (name === 'enquiries') await loadEnquiries(1);
    if (name === 'payments') await loadPayments(1);
    if (name === 'attempts') await loadAttempts(1);
    if (name === 'webhooks') await loadWebhooks(1);
  }

  async function refreshCurrentData() {
    if (refreshInProgress) return;

    refreshInProgress = true;
    refreshButton.disabled = true;
    refreshButton.textContent = 'Refreshing…';
    refreshMessage.textContent = 'Refreshing current data…';
    refreshMessage.classList.remove('is-error');

    const activeView = document.querySelector('[data-panel]:not([hidden])')?.dataset.panel || 'dashboard';
    const requests = [loadOverview()];
    if (activeView === 'enquiries') requests.push(loadEnquiries(state.enquiriesPage));
    if (activeView === 'payments') requests.push(loadPayments(state.paymentsPage));
    if (activeView === 'attempts') requests.push(loadAttempts(state.attemptsPage));
    if (activeView === 'webhooks') requests.push(loadWebhooks(state.webhooksPage));

    try {
      const results = await Promise.all(requests);
      if (results.every(Boolean)) {
        markRefreshSuccess();
        refreshMessage.textContent = 'Refresh complete.';
      } else {
        refreshMessage.textContent = 'Refresh incomplete. Previously loaded records were preserved.';
        refreshMessage.classList.add('is-error');
      }
    } catch (error) {
      refreshMessage.textContent = 'Refresh failed. Previously displayed data was preserved.';
      refreshMessage.classList.add('is-error');
    } finally {
      refreshInProgress = false;
      refreshButton.disabled = false;
      refreshButton.textContent = 'Refresh Data';
    }
  }

  document.querySelectorAll('.nav-item[data-view]').forEach(button => {
    button.addEventListener('click', () => showView(button.dataset.view));
  });
  document.querySelectorAll('[data-open-view]').forEach(button => {
    button.addEventListener('click', () => showView(button.dataset.openView));
  });
  document.getElementById('enquiryFilters').addEventListener('submit', event => {
    event.preventDefault();
    loadEnquiries(1);
  });
  document.getElementById('paymentFilters').addEventListener('submit', event => {
    event.preventDefault();
    loadPayments(1);
  });
  document.getElementById('attemptFilters').addEventListener('submit', event => {
    event.preventDefault();
    loadAttempts(1);
  });
  document.getElementById('webhookFilters').addEventListener('submit', event => {
    event.preventDefault();
    loadWebhooks(1);
  });
  refreshButton.addEventListener('click', refreshCurrentData);
  document.getElementById('mobileMenuButton').addEventListener('click', event => {
    const open = document.body.classList.toggle('sidebar-open');
    event.currentTarget.setAttribute('aria-expanded', String(open));
  });
  document.getElementById('closeDialog').addEventListener('click', () => detailDialog.close());
  detailDialog.addEventListener('click', event => {
    if (event.target === detailDialog) detailDialog.close();
  });
  document.querySelectorAll('[data-logout]').forEach(button => {
    button.addEventListener('click', async () => {
      if (!window.confirm('Sign out of the admin dashboard?')) return;
      button.disabled = true;
      try {
        await readResponse(await fetch(`${authEndpoint}?action=logout`, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'X-CSRF-Token': csrfToken }
        }));
        window.location.href = 'login.php';
      } catch (error) {
        button.disabled = false;
        window.alert(error.message);
      }
    });
  });

  loadOverview();
})();
