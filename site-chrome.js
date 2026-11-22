(() => {
  const menuButton = document.getElementById('siteMenuToggle');
  const navigation = document.getElementById('siteNavigation');
  const header = document.querySelector('.site-chrome-header');
  const closeMenu = () => {
    if (!menuButton || !navigation) return;
    navigation.classList.remove('is-open');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Open menu');
    menuButton.querySelector('i').className = 'fa-solid fa-bars';
    menuButton.querySelector('span').textContent = 'Menu';
  };

  if (menuButton && navigation) {
    menuButton.addEventListener('click', () => {
      const isOpen = navigation.classList.toggle('is-open');
      menuButton.setAttribute('aria-expanded', String(isOpen));
      menuButton.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
      menuButton.querySelector('i').className = `fa-solid ${isOpen ? 'fa-xmark' : 'fa-bars'}`;
      menuButton.querySelector('span').textContent = isOpen ? 'Close' : 'Menu';
    });

    navigation.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeMenu();
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth > 960) closeMenu();
    });
    window.closeSiteMenu = closeMenu;
  }

  const updateHeaderState = () => {
    if (header) header.classList.toggle('is-scrolled', window.scrollY > 8);
  };
  window.addEventListener('scroll', updateHeaderState, { passive: true });
  updateHeaderState();

  const currentPage = location.pathname.split('/').pop() || 'index.html';
  navigation?.querySelectorAll('a[data-page]').forEach(link => {
    if (link.dataset.page === currentPage) link.setAttribute('aria-current', 'page');
  });
  document.querySelectorAll('[data-current-year]').forEach(element => {
    element.textContent = new Date().getFullYear();
  });

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (window.gsap && !reduceMotion) {
    window.gsap.fromTo('.site-chrome-inner',
      { autoAlpha: 0, y: -8 },
      { autoAlpha: 1, y: 0, duration: .55, ease: 'power2.out' }
    );

    if (window.ScrollTrigger) {
      window.gsap.registerPlugin(window.ScrollTrigger);
      window.gsap.fromTo('.site-chrome-footer',
        { autoAlpha: 0, y: 16 },
        {
          autoAlpha: 1,
          y: 0,
          duration: .55,
          ease: 'power2.out',
          scrollTrigger: {
            trigger: '.site-chrome-footer',
            start: 'top 95%',
            once: true
          }
        }
      );
    }
  }
})();
