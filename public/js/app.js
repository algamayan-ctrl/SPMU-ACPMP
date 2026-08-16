(() => {
    const root = document.documentElement;
    const storageKey = root.dataset.themeStorageKey;
    const selectors = document.querySelectorAll('[data-appearance-select]');
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    const allowedPreferences = ['light', 'dark', 'system'];

    if (!storageKey) {
        return;
    }

    const storedPreference = () => {
        const current = root.dataset.themePreference;
        return allowedPreferences.includes(current) ? current : 'light';
    };

    const resolvedTheme = (preference) => preference === 'dark' ? 'dark' : 'light';

    const readablePreference = (preference) => preference === 'system' ? 'Default' : `${preference[0].toUpperCase()}${preference.slice(1)}`;

    const updateControls = (preference) => {
        selectors.forEach((select) => {
            select.value = preference;
            const status = select.closest('.appearance-settings-card')?.querySelector('[data-appearance-status]');
            if (status) {
                status.textContent = preference === 'system'
                    ? 'Default theme is light.'
                    : `${readablePreference(preference)} mode is selected for this account on this browser.`;
            }
        });
    };

    const applyPreference = (preference, persist = false) => {
        if (!allowedPreferences.includes(preference)) {
            preference = 'system';
        }

        const theme = resolvedTheme(preference);
        root.dataset.themePreference = preference;
        root.dataset.theme = theme;
        root.style.colorScheme = theme;

        if (persist) {
            try {
                localStorage.setItem(storageKey, preference);
            } catch (_) {}
        }

        updateControls(preference);
    };

    selectors.forEach((select) => {
        select.addEventListener('change', () => applyPreference(select.value, true));
    });

    systemTheme.addEventListener('change', () => {
        // Default is now always light, so no need to reapply on system theme change
    });

    applyPreference(storedPreference());
})();

(() => {
    const sidebar = document.getElementById('primary-sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const closeControls = document.querySelectorAll('[data-sidebar-close]');
    const mobileSidebar = window.matchMedia('(max-width: 1049px)');

    if (!sidebar || !toggle) {
        return;
    }

    const setSidebarOpen = (open, restoreFocus = false) => {
        open = Boolean(open && mobileSidebar.matches);
        document.body.classList.toggle('sidebar-open', open);
        toggle.setAttribute('aria-expanded', String(open));

        if (open) {
            sidebar.querySelector('a, button')?.focus();
        } else if (restoreFocus) {
            toggle.focus();
        }
    };

    toggle.addEventListener('click', () => {
        if (mobileSidebar.matches) {
            setSidebarOpen(!document.body.classList.contains('sidebar-open'));
        }
    });

    closeControls.forEach((control) => {
        control.addEventListener('click', () => setSidebarOpen(false, true));
    });

    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (mobileSidebar.matches) {
                setSidebarOpen(false);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
            setSidebarOpen(false, true);
        }
    });

    mobileSidebar.addEventListener('change', () => {
        if (!mobileSidebar.matches) {
            setSidebarOpen(false);
        }
    });

    setSidebarOpen(false);
})();

(() => {
    const calendar = document.querySelector('[data-borrowing-calendar]');
    const drawer = document.querySelector('[data-calendar-drawer]');
    const drawerBackdrop = document.querySelector('.calendar-drawer-backdrop');

    if (!calendar || !drawer || !drawerBackdrop) {
        return;
    }

    const drawerContent = drawer.querySelector('[data-calendar-drawer-content]');
    const drawerClose = drawer.querySelector('[data-calendar-drawer-close]');
    const viewButtons = calendar.querySelectorAll('[data-calendar-view-button]');
    const viewPanels = calendar.querySelectorAll('[data-calendar-view-panel]');
    const compactViewport = window.matchMedia('(max-width: 700px)');
    let lastTrigger = null;
    let closeTimer = null;

    const selectView = (view) => {
        viewButtons.forEach((button) => {
            const selected = button.dataset.calendarViewButton === view;
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
        viewPanels.forEach((panel) => {
            panel.hidden = panel.dataset.calendarViewPanel !== view;
        });
    };

    const openDrawer = (template, trigger) => {
        if (!template || !drawerContent) {
            return;
        }

        window.clearTimeout(closeTimer);
        drawerContent.replaceChildren(template.content.cloneNode(true));
        if (!drawer.contains(trigger)) {
            lastTrigger = trigger;
        }
        drawer.hidden = false;
        drawerBackdrop.hidden = false;
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('calendar-drawer-open');

        window.requestAnimationFrame(() => {
            drawer.classList.add('is-open');
            drawerBackdrop.classList.add('is-open');
            drawerClose?.focus();
        });
    };

    const closeDrawer = (restoreFocus = true) => {
        if (drawer.hidden) {
            return;
        }

        drawer.classList.remove('is-open');
        drawerBackdrop.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('calendar-drawer-open');
        closeTimer = window.setTimeout(() => {
            drawer.hidden = true;
            drawerBackdrop.hidden = true;
            drawerContent?.replaceChildren();
        }, 190);

        if (restoreFocus && lastTrigger?.isConnected) {
            lastTrigger.focus();
        }
    };

    const activateCalendarControl = (target) => {
        const eventTrigger = target.closest('[data-calendar-event]');
        if (eventTrigger && (calendar.contains(eventTrigger) || drawer.contains(eventTrigger))) {
            const template = document.getElementById(`calendar-detail-${eventTrigger.dataset.calendarEvent}`);
            openDrawer(template, eventTrigger);
            return true;
        }

        const dayTrigger = target.closest('[data-calendar-day]');
        if (dayTrigger && calendar.contains(dayTrigger)) {
            const template = document.getElementById(`calendar-day-${dayTrigger.dataset.calendarDay}`);
            openDrawer(template, dayTrigger);
            return true;
        }

        return false;
    };

    viewButtons.forEach((button) => {
        button.addEventListener('click', () => selectView(button.dataset.calendarViewButton));
    });

    calendar.addEventListener('click', (event) => activateCalendarControl(event.target));
    drawerContent?.addEventListener('click', (event) => activateCalendarControl(event.target));
    document.querySelectorAll('[data-calendar-drawer-close]').forEach((control) => {
        control.addEventListener('click', () => closeDrawer());
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !drawer.hidden) {
            closeDrawer();
        }
    });

    selectView(compactViewport.matches ? 'list' : 'month');
})();

(() => {
    const menus = document.querySelectorAll('[data-account-menu]');

    const setMenuOpen = (menu, open, restoreFocus = false) => {
        const toggle = menu.querySelector('[data-account-menu-toggle]');
        const dropdown = menu.querySelector('[data-account-menu-dropdown]');

        menu.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        dropdown.setAttribute('aria-hidden', String(!open));

        if (restoreFocus) {
            toggle.focus();
        }
    };

    menus.forEach((menu) => {
        const toggle = menu.querySelector('[data-account-menu-toggle]');
        const dropdown = menu.querySelector('[data-account-menu-dropdown]');

        if (!toggle || !dropdown) {
            return;
        }

        toggle.addEventListener('click', () => {
            setMenuOpen(menu, !menu.classList.contains('is-open'));
        });

        dropdown.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => setMenuOpen(menu, false));
        });
    });

    document.addEventListener('pointerdown', (event) => {
        menus.forEach((menu) => {
            if (menu.classList.contains('is-open') && !menu.contains(event.target)) {
                setMenuOpen(menu, false);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        menus.forEach((menu) => {
            if (menu.classList.contains('is-open')) {
                setMenuOpen(menu, false, true);
            }
        });
    });
})();

(() => {
    const passwordToggle = document.querySelector('[data-toggle-password]');
    const passwordInput = document.querySelector('#password');

    if (!passwordToggle || !passwordInput) {
        return;
    }

    let isPasswordVisible = false;

    const updatePasswordVisibility = () => {
        if (isPasswordVisible) {
            passwordInput.type = 'text';
            passwordToggle.textContent = 'Hide';
            passwordToggle.setAttribute('aria-label', 'Hide password');
            passwordToggle.setAttribute('title', 'Hide password');
        } else {
            passwordInput.type = 'password';
            passwordToggle.textContent = 'Show';
            passwordToggle.setAttribute('aria-label', 'Show password');
            passwordToggle.setAttribute('title', 'Show password');
        }
    };

    passwordToggle.addEventListener('click', (event) => {
        event.preventDefault();
        isPasswordVisible = !isPasswordVisible;
        updatePasswordVisibility();
    });

    updatePasswordVisibility();
})();

(() => {
    const workflowSteps = document.querySelectorAll('.landing-workflow article');

    if (workflowSteps.length === 0) {
        return;
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion) {
        workflowSteps.forEach((step) => {
            step.classList.add('is-revealed');
        });
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-revealed');
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px',
    });

    workflowSteps.forEach((step) => {
        observer.observe(step);
    });
})();

(() => {
    const smoothScrollLinks = document.querySelectorAll('a[href^="#"]');

    if (smoothScrollLinks.length === 0) {
        return;
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    smoothScrollLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            const href = link.getAttribute('href');
            if (href === '#') {
                return;
            }

            const target = document.querySelector(href);
            if (!target) {
                return;
            }

            event.preventDefault();

            if (prefersReducedMotion) {
                target.scrollIntoView();
                target.focus({ preventScroll: true });
            } else {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
})();

(() => {
    const forms = document.querySelectorAll('[data-approval-decision-form]');

    forms.forEach((form) => {
        const decision = form.querySelector('[data-approval-decision]');
        const remarks = form.querySelector('[data-approval-remarks]');
        const remarksLabel = form.querySelector('[data-approval-remarks-label]');
        const remarksHelp = form.querySelector('[data-approval-remarks-help]');
        const panel = form.closest('[data-approval-decision-panel]');

        if (!decision || !remarks || !remarksLabel || !remarksHelp || !panel) {
            return;
        }

        const updateDecisionState = () => {
            const value = decision.value;
            const isReturn = value === 'RETURNED_FOR_REVISION';
            const isReject = value === 'REJECTED';
            const reasonRequired = isReturn || isReject;

            remarks.required = reasonRequired;
            remarksLabel.textContent = isReject
                ? 'Reason for rejection (required)'
                : (isReturn ? 'Reason for return (required)' : 'Remarks (optional)');
            remarksHelp.textContent = reasonRequired
                ? 'A reason is required for this decision.'
                : (value === 'APPROVED'
                    ? 'Remarks are optional when approving.'
                    : 'A reason is required when returning or rejecting a request.');
            panel.dataset.decisionTone = value === 'APPROVED'
                ? 'approve'
                : (isReturn ? 'return' : (isReject ? 'reject' : 'neutral'));
        };

        decision.addEventListener('change', updateDecisionState);
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                return;
            }

            const selectedLabel = decision.selectedOptions[0]?.textContent?.trim() || 'selected';
            const confirmed = window.confirm(`Submit the “${selectedLabel}” decision? This records the decision, request history, and your e-signature snapshot.`);
            if (!confirmed) {
                event.preventDefault();
            }
        });

        updateDecisionState();
    });
})();

(() => {
    document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                return;
            }

            const message = form.dataset.confirmMessage;
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
})();
