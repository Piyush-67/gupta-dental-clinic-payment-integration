(() => {
  const cfg = window.GDC_DEMO_CONFIG || {};
  const WA = cfg.whatsappNumber || '917739957487';

  // The homepage's compact form routes visitors to the complete agreed intake form.
  const homeForm = document.getElementById('appointment-form');
  if (homeForm) {
    homeForm.addEventListener('submit', event => {
      event.preventDefault();
      if (!homeForm.reportValidity()) return;
      window.location.href = 'contact-us.html#contact-form';
    });
  }

  const modalForm = document.getElementById('modal-form');
  if (modalForm) modalForm.addEventListener('submit', event => {
    event.preventDefault();
    if (modalForm.reportValidity()) window.location.href = 'contact-us.html#contact-form';
  });

  const form = document.getElementById('contactForm');
  if (form) {
    const status = document.getElementById('formStatus');
    const submit = document.getElementById('submitEnquiry');
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const message = 'Hello Gupta Dental Clinic, I would like to enquire. Please contact me.';
      const waUrl = `https://wa.me/${WA}?text=${encodeURIComponent(message)}`;
      status.textContent = 'Your details have not been saved or sent. Continue to WhatsApp to contact the clinic; your form details will not be transferred automatically.';
      status.style.color = '#087443';
      if (submit) submit.classList.add('hidden');
      const link = document.createElement('a');
      link.className = 'btn form-submit';
      link.href = waUrl;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.innerHTML = '<i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Continue to WhatsApp';
      status.insertAdjacentElement('afterend', link);
    });
  }
})();
