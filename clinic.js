(() => {
  const homeForm = document.getElementById('appointment-form');
  if (homeForm) {
    homeForm.addEventListener('submit', event => {
      event.preventDefault();
      if (!homeForm.reportValidity()) return;
      window.location.href = 'contact-us.html#contact-form';
    });
  }

  const modalForm = document.getElementById('modal-form');
  if (modalForm) {
    modalForm.addEventListener('submit', event => {
      event.preventDefault();
      if (modalForm.reportValidity()) {
        window.location.href = 'contact-us.html#contact-form';
      }
    });
  }

  const form = document.getElementById('contactForm');
  if (!form) return;

  const status = document.getElementById('formStatus');
  const submit = document.getElementById('submitEnquiry');
  const apiBase = new URL(
    'gupta-dental-payment-backend/api/',
    document.baseURI
  );
  const requestTokenKey = 'gdc.enquiry.request-token';
  const defaultButtonText = 'Proceed to Payment · ₹5 →';

  let requestToken = null;
  let currentOrder = null;
  let isBusy = false;
  let checkoutOpen = false;
  let paymentVerificationPending = false;
  let paymentCompleted = false;

  function setStatus(message, kind = 'info') {
    const colors = {
      info: '#52647a',
      success: '#087443',
      warning: '#9a5a00',
      error: '#b42318'
    };

    status.textContent = message;
    status.style.color = colors[kind] || colors.info;
  }

  function setButton(disabled, text = defaultButtonText) {
    submit.disabled = disabled;
    submit.textContent = text;
  }

  function setFieldsDisabled(disabled) {
    Array.from(form.elements).forEach(element => {
      if (element !== submit) element.disabled = disabled;
    });
  }

  function createRequestToken() {
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
  }

  function getRequestToken() {
    if (!requestToken) {
      const saved = sessionStorage.getItem(requestTokenKey);
      requestToken = /^[a-f0-9]{64}$/.test(saved || '')
        ? saved
        : createRequestToken();
      sessionStorage.setItem(requestTokenKey, requestToken);
    }

    return requestToken;
  }

  async function postJson(endpoint, data) {
    const response = await fetch(new URL(endpoint, apiBase), {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });

    let result;

    try {
      result = await response.json();
    } catch (error) {
      throw new Error('The server returned an unreadable response.');
    }

    if (!response.ok || !result.success) {
      const failure = new Error(
        result.message || 'The request could not be completed.'
      );
      failure.status = response.status;
      throw failure;
    }

    return result.data;
  }

  function getEnquiryData() {
    const data = new FormData(form);

    return {
      request_token: getRequestToken(),
      full_name: data.get('name'),
      primary_phone: data.get('phone'),
      alternate_phone: data.get('alternate_phone') || '',
      complete_address: data.get('address'),
      age: data.get('age'),
      email: data.get('email') || '',
      reason_for_visit: data.get('reason'),
      consent: data.get('consent') === 'on'
    };
  }

  function showSuccess(reference) {
    if (typeof reference !== 'string' || !/^GDC-[0-9]{6,}$/.test(reference)) {
      throw new Error('The server did not return a valid enquiry reference.');
    }

    paymentCompleted = true;
    paymentVerificationPending = false;
    currentOrder = null;
    setFieldsDisabled(true);
    setStatus(
      `Payment successful. Your enquiry has been submitted. Reference: ${reference}. The clinic will contact you to coordinate an appointment.`,
      'success'
    );
    setButton(true, 'Payment Successful');
  }

  function applyPaymentState(data) {
    if (data.payment_status === 'captured') {
      showSuccess(data.reference);
      return;
    }

    if (data.order_creation_status && data.order_creation_status !== 'created') {
      currentOrder = null;
      paymentVerificationPending = true;
      setFieldsDisabled(true);
      setStatus(
        data.order_creation_status === 'creating'
          ? 'Your payment order is still being confirmed. Do not submit a new enquiry; check the status again shortly.'
          : 'The payment order could not be confirmed. Do not submit another enquiry or payment; contact the clinic if this status continues.',
        'warning'
      );
      setButton(false, 'Check Payment Status');
      return;
    }

    if (data.order) {
      currentOrder = data.order;
    }

    if (data.payment_status === 'authorized') {
      paymentVerificationPending = true;
      setFieldsDisabled(true);
      setStatus(
        'Your payment is authorized but not yet captured. Please do not pay again; the clinic is checking its status.',
        'warning'
      );
      setButton(false, 'Check Payment Status');
      return;
    }

    paymentVerificationPending = false;

    if (currentOrder) {
      setFieldsDisabled(false);
      setStatus(
        'Your enquiry is saved. Continue with the same payment order to complete the ₹5 processing fee.',
        'info'
      );
      setButton(false, 'Resume Payment · ₹5 →');
    }
  }

  async function checkPaymentStatus() {
    if (!requestToken) return false;

    try {
      const data = await postJson('enquiry-status.php', {
        request_token: requestToken
      });
      applyPaymentState(data);
      return paymentCompleted;
    } catch (error) {
      if (error.status === 404) {
        paymentVerificationPending = false;
        currentOrder = null;
        setFieldsDisabled(false);
        setButton(false);
        setStatus(
          'Complete the form to submit your enquiry and continue to payment.',
          'info'
        );
        return false;
      }

      paymentVerificationPending = true;
      setStatus(
        'Payment status could not be confirmed. Please do not submit another enquiry or pay again; retry the status check shortly.',
        'warning'
      );
      setButton(false, 'Check Payment Status');
      return false;
    }
  }

  async function pollPaymentStatus() {
    for (let attempt = 0; attempt < 4; attempt += 1) {
      if (await checkPaymentStatus()) return true;
      if (paymentVerificationPending || currentOrder) return false;
      await new Promise(resolve => window.setTimeout(resolve, 1500));
    }

    return paymentCompleted;
  }

  async function openCheckout(order) {
    if (checkoutOpen || paymentCompleted || paymentVerificationPending) return;

    if (typeof window.Razorpay !== 'function') {
      throw new Error('Razorpay Checkout could not be loaded. Please try again.');
    }

    checkoutOpen = true;
    setFieldsDisabled(true);
    setButton(true, 'Opening Secure Checkout…');

    const checkout = new window.Razorpay({
      key: order.key_id,
      amount: order.amount,
      currency: order.currency,
      name: 'Gupta Dental Clinic',
      description: 'Dental enquiry processing fee',
      order_id: order.order_id,
      theme: { color: '#176B57' },
      handler: async response => {
        checkoutOpen = false;
        isBusy = true;
        paymentVerificationPending = true;
        setButton(true, 'Verifying Payment…');
        setStatus('Payment received. Verifying securely…', 'info');

        try {
          const result = await postJson('verify-payment.php', response);

          if (result.payment_status === 'captured') {
            showSuccess(result.reference);
            return;
          }

          await pollPaymentStatus();
        } catch (error) {
          await pollPaymentStatus();
        } finally {
          isBusy = false;
          if (!paymentCompleted && paymentVerificationPending) {
            setButton(false, 'Check Payment Status');
          }
        }
      },
      modal: {
        ondismiss: async () => {
          checkoutOpen = false;

          if (paymentCompleted) return;

          if (paymentVerificationPending) {
            await checkPaymentStatus();
            return;
          }

          await checkPaymentStatus();

          if (!paymentCompleted && !paymentVerificationPending && currentOrder) {
            setFieldsDisabled(false);
            setStatus(
              'Checkout was closed. If you attempted payment, check with your bank before retrying. The same order will be reused.',
              'warning'
            );
            setButton(false, 'Resume Payment · ₹5 →');
          }
        }
      }
    });

    checkout.on('payment.failed', response => {
      checkoutOpen = false;
      const description =
        response?.error?.description || 'Payment attempt failed.';
      setStatus(
        `${description} You can retry using the same payment order.`,
        'error'
      );
      setFieldsDisabled(false);
      setButton(false, 'Retry Payment · ₹5 →');
    });

    try {
      checkout.open();
    } catch (error) {
      checkoutOpen = false;
      throw error;
    }
  }

  form.addEventListener('input', () => {
    if (
      !requestToken ||
      isBusy ||
      checkoutOpen ||
      paymentVerificationPending ||
      paymentCompleted
    ) return;

    sessionStorage.removeItem(requestTokenKey);
    requestToken = null;
    currentOrder = null;
    paymentVerificationPending = false;
    setButton(false);
    setStatus(
      'Changing your details will start a new enquiry when you submit.',
      'info'
    );
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();

    if (isBusy || checkoutOpen || paymentCompleted) return;
    if (!form.reportValidity()) return;

    isBusy = true;
    setButton(true, 'Processing Enquiry…');

    try {
      const enquiryData = !currentOrder && !paymentVerificationPending
        ? getEnquiryData()
        : null;
      setFieldsDisabled(true);

      if (paymentVerificationPending) {
        await checkPaymentStatus();
        return;
      }

      const order = currentOrder || await postJson(
        'create-order.php',
        enquiryData
      );

      if (order.payment_status === 'captured') {
        showSuccess(order.reference);
        return;
      }

      if (order.order_creation_status !== 'created' || !order.order_id) {
        applyPaymentState(order);
        return;
      }

      currentOrder = order;
      await openCheckout(order);
    } catch (error) {
      if (!paymentVerificationPending && !currentOrder && requestToken) {
        await checkPaymentStatus();
      }

      if (!paymentVerificationPending && !currentOrder) {
        setFieldsDisabled(false);
        setStatus(
          error.message || 'Unable to start checkout. Retry with the same details.',
          'error'
        );
        setButton(false, 'Retry Enquiry · ₹5 →');
      } else if (currentOrder && !paymentVerificationPending) {
        setFieldsDisabled(false);
        setStatus(
          error.message || 'Unable to open checkout. Resume the same payment order.',
          'error'
        );
        setButton(false, 'Resume Payment · ₹5 →');
      }
    } finally {
      isBusy = false;
    }
  });

  const savedToken = sessionStorage.getItem(requestTokenKey);

  if (/^[a-f0-9]{64}$/.test(savedToken || '')) {
    requestToken = savedToken;
    isBusy = true;
    setButton(true, 'Checking Payment Status…');
    setStatus('Checking your saved enquiry and payment status…', 'info');

    checkPaymentStatus().finally(() => {
      isBusy = false;
      if (!paymentCompleted && !paymentVerificationPending && !currentOrder) {
        setButton(false);
        setStatus(
          'Complete the form to submit your enquiry and continue to payment.',
          'info'
        );
      }
    });
  }
})();
