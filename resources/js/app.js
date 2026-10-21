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

// Confetti when a badge unlocks. A short burst on a canvas; skipped for reduced motion.
window.addEventListener('confetti', () => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const canvas = Object.assign(document.createElement('canvas'), { width: innerWidth, height: innerHeight });
    canvas.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:60';
    document.body.append(canvas);
    const ctx = canvas.getContext('2d');
    const colors = ['#bf0d3e', '#ed8b00', '#009cde', '#00b140', '#e0b400', '#919d9d'];
    const pieces = Array.from({ length: 140 }, () => ({
        x: innerWidth / 2, y: innerHeight / 3,
        vx: (Math.random() - 0.5) * 14, vy: Math.random() * -12 - 4,
        size: 5 + Math.random() * 6, color: colors[Math.floor(Math.random() * colors.length)],
        spin: Math.random() * 6, angle: 0,
    }));

    let frame = 0;
    const draw = () => {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        pieces.forEach((p) => {
            p.vy += 0.35; p.x += p.vx; p.y += p.vy; p.angle += p.spin * 0.02;
            ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.angle);
            ctx.fillStyle = p.color; ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
            ctx.restore();
        });
        if (++frame < 110) requestAnimationFrame(draw); else canvas.remove();
    };
    draw();
});

// Tooltips for anything with data-tip. One floating label, positioned above the element
// and kept on screen, so it isn't clipped by scrolling containers like the strip maps.
(() => {
    let tip;
    let target;

    const show = (el) => {
        tip ??= Object.assign(document.createElement('div'), { role: 'tooltip', id: 'stoop-tip' });
        tip.className = 'pointer-events-none fixed z-[70] max-w-64 rounded-md bg-ink px-2.5 py-1.5 text-[12.5px] leading-snug text-paper shadow-lg';
        tip.textContent = el.dataset.tip;
        document.body.append(tip);

        const box = el.getBoundingClientRect();
        const width = tip.offsetWidth;
        const left = Math.min(Math.max(8, box.left + box.width / 2 - width / 2), innerWidth - width - 8);
        const above = box.top - tip.offsetHeight - 8;
        tip.style.left = `${left}px`;
        tip.style.top = `${above > 8 ? above : box.bottom + 8}px`;

        target = el;
        el.setAttribute('aria-describedby', 'stoop-tip');
    };

    const hide = () => {
        tip?.remove();
        target?.removeAttribute('aria-describedby');
        target = null;
    };

    document.addEventListener('mouseover', (e) => {
        const el = e.target.closest?.('[data-tip]');
        if (el && el !== target) show(el);
        if (!el && target) hide();
    });
    document.addEventListener('focusin', (e) => {
        const el = e.target.closest?.('[data-tip]');
        el ? show(el) : hide();
    });
    document.addEventListener('focusout', hide);
    document.addEventListener('scroll', hide, true);
    document.addEventListener('keydown', (e) => e.key === 'Escape' && hide());
    document.addEventListener('livewire:navigated', hide);
})();
