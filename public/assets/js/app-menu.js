const navigationDisclosure = document.querySelector('.site-navigation__disclosure');

if (navigationDisclosure instanceof HTMLDetailsElement) {
    const toggle = navigationDisclosure.querySelector('summary');
    const navigation = navigationDisclosure.querySelector('.site-navigation');
    const mobileViewport = window.matchMedia('(max-width: 1024px)');
    const syncNavigation = () => {
        if (mobileViewport.matches) {
            const focusIsInNavigation = navigation?.contains(document.activeElement) ?? false;
            navigationDisclosure.open = false;
            if (focusIsInNavigation) {
                toggle?.focus();
            }
        } else {
            const focusIsOnToggle = toggle === document.activeElement;
            navigationDisclosure.open = true;
            if (focusIsOnToggle) {
                navigation?.querySelector('a, button')?.focus();
            }
        }
        toggle?.setAttribute('aria-expanded', String(navigationDisclosure.open));
    };

    syncNavigation();
    mobileViewport.addEventListener('change', syncNavigation);
    navigationDisclosure.addEventListener('toggle', () => {
        toggle?.setAttribute('aria-expanded', String(navigationDisclosure.open));
    });
    navigationDisclosure.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileViewport.matches && navigationDisclosure.open) {
            event.preventDefault();
            navigationDisclosure.open = false;
            toggle?.focus();
        }
    });
}
