(() => {
  if (window.siteChromeInitialized) return;
  window.siteChromeInitialized = true;

  const header = document.querySelector('.site-chrome-header');
  const menuButton = header?.querySelector('#siteMenuToggle');
  const navigation = header?.querySelector('#siteNavigation');
  const aboutDropdown = navigation?.querySelector('.site-chrome-dropdown');
  const aboutButton = aboutDropdown?.querySelector('.site-chrome-dropdown-toggle');
  const desktopViewport = window.matchMedia('(min-width: 1321px)');

  const setDropdownOpen = open => {
    if (!aboutDropdown || !aboutButton) return;
    aboutDropdown.classList.toggle('is-open', open);
    aboutButton.setAttribute('aria-expanded', String(open));
  };

  const setMenuOpen = open => {
    if (!menuButton || !navigation) return;
    navigation.classList.toggle('is-open', open);
    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    const icon = menuButton.querySelector('i');
    const label = menuButton.querySelector('span');
    if (icon) {
      icon.classList.toggle('fa-bars', !open);
      icon.classList.toggle('fa-xmark', open);
    }
    if (label) label.textContent = open ? 'Close' : 'Menu';
    if (!open) setDropdownOpen(false);
  };

  if (navigation && !navigation.dataset.siteChromeInitialized) {
    navigation.dataset.siteChromeInitialized = 'true';
    let dropdownPointerInside = false;

    menuButton?.addEventListener('click', () => {
      setMenuOpen(menuButton.getAttribute('aria-expanded') !== 'true');
    });

    aboutDropdown?.addEventListener('pointerenter', event => {
      if (!desktopViewport.matches || event.pointerType === 'touch') return;
      dropdownPointerInside = true;
      setDropdownOpen(true);
    });

    aboutDropdown?.addEventListener('pointerleave', event => {
      if (!desktopViewport.matches || event.pointerType === 'touch') return;
      dropdownPointerInside = false;
      if (!aboutDropdown.contains(document.activeElement)) setDropdownOpen(false);
    });

    aboutDropdown?.addEventListener('focusin', () => {
      if (desktopViewport.matches) setDropdownOpen(true);
    });
    aboutDropdown?.addEventListener('focusout', event => {
      if (desktopViewport.matches && !aboutDropdown.contains(event.relatedTarget) && !dropdownPointerInside) {
        setDropdownOpen(false);
      }
    });

    aboutButton?.addEventListener('click', () => {
      setDropdownOpen(desktopViewport.matches || aboutButton.getAttribute('aria-expanded') !== 'true');
    });

    navigation.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        setMenuOpen(false);
        setDropdownOpen(false);
      });
    });

    document.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      if (aboutDropdown?.classList.contains('is-open')) {
        dropdownPointerInside = false;
        setDropdownOpen(false);
      }
      if (navigation?.classList.contains('is-open')) {
        setMenuOpen(false);
        menuButton?.focus();
      }
    });

    window.addEventListener('resize', () => {
      setMenuOpen(false);
      setDropdownOpen(false);
      dropdownPointerInside = false;
    });

    window.closeSiteMenu = () => setMenuOpen(false);

    const currentPage = location.pathname.split('/').pop() || 'index.html';
    navigation.querySelectorAll('a[data-page]').forEach(link => {
      if (link.dataset.page === currentPage) link.setAttribute('aria-current', 'page');
    });
  }

  const updateHeaderState = () => {
    header?.classList.toggle('is-scrolled', window.scrollY > 8);
  };
  window.addEventListener('scroll', updateHeaderState, { passive: true });
  updateHeaderState();

  document.querySelectorAll('[data-current-year]').forEach(element => {
    element.textContent = new Date().getFullYear();
  });

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (window.gsap && !reduceMotion && header) {
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
