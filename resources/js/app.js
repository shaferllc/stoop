// @mention suggestions in the composer.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('mentionPicker', ($wire) => ({
        open: false,
        items: [],
        active: 0,
        start: null,

        async lookup() {
            const el = this.$refs.input;
            const before = el.value.slice(0, el.selectionStart);
            const match = before.match(/(^|[^\w.@])@(\w{0,30})$/);

            if (!match) {
                this.open = false;
                return;
            }

            this.start = el.selectionStart - match[2].length;
            this.items = await $wire.mentionSuggestions(match[2]);
            this.active = 0;
            this.open = true;
        },

        keys(event) {
            if (!this.open || !this.items.length) return;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.active = (this.active + step + this.items.length) % this.items.length;
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                this.pick(this.items[this.active]);
            } else if (event.key === 'Escape') {
                this.open = false;
            }
        },

        pick(item) {
            const el = this.$refs.input;
            const end = el.selectionStart;
            el.value = el.value.slice(0, this.start) + item.username + ' ' + el.value.slice(end);
            const caret = this.start + item.username.length + 1;
            el.setSelectionRange(caret, caret);
            el.dispatchEvent(new Event('input'));
            this.open = false;
            el.focus();
        },
    }));
});

// Profile preview when hovering a name. Skipped on touch screens.
document.addEventListener('alpine:init', () => {
    const cache = {};

    window.Alpine.data('hoverCard', (url) => ({
        open: false,
        html: '',
        timer: null,

        show() {
            if (window.matchMedia('(hover: none)').matches) return;
            this.timer = setTimeout(async () => {
                cache[url] ??= fetch(url, { headers: { Accept: 'text/html' } }).then((r) => (r.ok ? r.text() : ''));
                this.html = await cache[url];
                this.open = this.html !== '';
            }, 400);
        },

        hide() {
            clearTimeout(this.timer);
            this.open = false;
        },
    }));
});

// Keyboard shortcuts. Press ? on any page for the list.
(() => {
    let pendingG = false;

    const typing = (el) => el.closest('input, textarea, select, [contenteditable]');
    const cards = () => [...document.querySelectorAll('[data-dispatch]')];
    const go = (url) => (window.Livewire ? window.Livewire.navigate(url) : (window.location.href = url));

    const move = (step) => {
        const list = cards();
        if (!list.length) return;
        const i = list.indexOf(document.activeElement.closest('[data-dispatch]'));
        const next = list[Math.min(Math.max(i + step, 0), list.length - 1)];
        next.focus({ preventScroll: true });
        next.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    };

    document.addEventListener('keydown', (e) => {
        if (e.metaKey || e.ctrlKey || e.altKey || typing(e.target)) return;

        if (pendingG) {
            pendingG = false;
            const routes = { h: '/', n: '/notifications', s: '/saved', p: document.body.dataset.profile };
            if (routes[e.key]) {
                e.preventDefault();
                go(routes[e.key]);
            }
            return;
        }

        switch (e.key) {
            case 'j': e.preventDefault(); move(1); break;
            case 'k': e.preventDefault(); move(-1); break;
            case 'o':
            case 'Enter': {
                const card = document.activeElement.closest('[data-dispatch]');
                if (card) { e.preventDefault(); go(card.dataset.href); }
                break;
            }
            case 'c': {
                const box = [...document.querySelectorAll('[data-composer]')].find((el) => el.offsetParent !== null);
                e.preventDefault();
                if (box) { box.scrollIntoView({ block: 'center' }); box.focus(); } else { window.dispatchEvent(new CustomEvent('open-composer')); }
                break;
            }
            case '/': e.preventDefault(); document.getElementById('site-search')?.focus(); break;
            case 'g': pendingG = true; setTimeout(() => (pendingG = false), 1000); break;
            case '?': e.preventDefault(); document.getElementById('shortcuts')?.showModal(); break;
        }
    });
})();

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

// Push notifications on this device. Used by the button in Settings → Profile.
window.stoopPush = {
    supported: () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window,

    async status() {
        if (!this.supported()) return 'unsupported';
        if (Notification.permission === 'denied') return 'blocked';
        const reg = await navigator.serviceWorker.ready;
        return (await reg.pushManager.getSubscription()) ? 'on' : 'off';
    },

    async enable() {
        const key = document.querySelector('meta[name=vapid-key]')?.content;
        if (!key || (await Notification.requestPermission()) !== 'granted') return this.status();

        const reg = await navigator.serviceWorker.ready;
        const raw = atob(key.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (key.length % 4)) % 4));
        const subscription = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: Uint8Array.from(raw, (c) => c.charCodeAt(0)),
        });

        await this.send('POST', subscription.toJSON());
        return 'on';
    },

    async disable() {
        const reg = await navigator.serviceWorker.ready;
        const subscription = await reg.pushManager.getSubscription();
        if (subscription) {
            await this.send('DELETE', { endpoint: subscription.endpoint });
            await subscription.unsubscribe();
        }
        return 'off';
    },

    send(method, body) {
        return fetch('/push/subscriptions', {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content },
            body: JSON.stringify(body),
        });
    },
};
