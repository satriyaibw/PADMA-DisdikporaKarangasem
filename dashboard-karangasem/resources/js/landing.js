const mobileMenu = document.querySelector('[data-mobile-menu]');

if (mobileMenu instanceof HTMLDetailsElement) {
    mobileMenu.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('a')) {
            mobileMenu.removeAttribute('open');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileMenu.open) {
            mobileMenu.open = false;
            mobileMenu.querySelector('summary')?.focus();
        }
    });
}
