/*
 * Stoop boxes for magazine article pages. Add any of these, then the script once:
 *
 *   <div data-stoop-discussion></div>                 neighbors talking about this article
 *   <div data-stoop-neighborhood="E02"></div>         what neighbors at a Metro stop are saying
 *   <div data-stoop-place="le-diplomate"></div>       a place's Been here count and check-ins (slug or id)
 *   <div data-stoop-quote="12"></div>                 a reader quote its author allowed
 *   <div data-stoop-poll="345"></div>                 live results of a reader poll
 *   <div data-stoop-byline="ann-limpert"></div>       a writer's byline with a follow link
 *   <script src="{{ route('embed') }}" async></script>
 *
 * The discussion box uses the page's canonical URL, or data-url="…" on the div. Styles live
 * in a shadow root so they can't clash with the article page. Requests never send cookies,
 * and nothing here counts views or sets identifiers.
 */
(() => {
    const base = @json(url('/'));

    const css = `
        :host { all: initial; display: block; font: 15px/1.5 system-ui, -apple-system, sans-serif; color: #16181a; }
        .box { border: 1px solid #dde0de; border-radius: 12px; padding: 16px; background: #fff; }
        .kicker { font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #c8102e; }
        h3 { margin: 4px 0 10px; font: 700 20px/1.25 Georgia, serif; }
        ul { list-style: none; margin: 0 0 12px; padding: 0; }
        li { padding: 8px 0; border-top: 1px dashed #dde0de; font-size: 14px; }
        li a { color: inherit; text-decoration: none; }
        li a:hover, li a:focus-visible { text-decoration: underline; }
        li b { font-weight: 600; }
        a:focus-visible { outline: 2px solid #c8102e; outline-offset: 2px; }
        .muted { color: #5c6366; font-size: 13px; }
        .cta { display: inline-block; background: #c8102e; color: #fff; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 14px; }
        blockquote { margin: 0; font: 18px/1.45 Georgia, serif; }
        .row { display: flex; align-items: center; gap: 12px; }
        .photo { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; background: #eef0ef; }
        .bar { position: relative; border: 1px solid #dde0de; border-radius: 8px; padding: 6px 10px; margin: 6px 0; display: flex; justify-content: space-between; overflow: hidden; }
        .bar i { position: absolute; inset: 0 auto 0 0; background: #eef0ef; }
        .bar span { position: relative; }
        @media (prefers-color-scheme: dark) {
            :host { color: #eceeed; }
            .box { background: #1b1e20; border-color: #2b2f31; }
            li, .bar { border-color: #2b2f31; }
            .bar i, .photo { background: #2b2f31; }
            .muted { color: #9aa2a5; }
        }
    `;

    const el = (tag, attrs = {}, text) => {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const out = (href, text, cls) => el('a', { href, target: '_blank', rel: 'noopener', ...(cls ? { class: cls } : {}) }, text);
    const clip = (text, n = 160) => (text.length > n ? text.slice(0, n) + '…' : text);
    const get = (path) => fetch(base + path, { credentials: 'omit' }).then((r) => (r.ok ? r.json() : Promise.reject(r)));

    // Draws a box into each matching element, once. A box that fails to load stays empty.
    const mount = (attr, path, draw, refreshSeconds) => {
        document.querySelectorAll(`[${attr}]`).forEach(async (host) => {
            if (host.shadowRoot) return;
            const value = host.getAttribute(attr);
            let data;
            try {
                data = await get(path(value, host));
            } catch {
                return;
            }
            const root = host.attachShadow({ mode: 'open' });
            const render = (d) => {
                const box = el('section', { class: 'box' });
                draw(box, d);
                root.replaceChildren(el('style', {}, css), box);
            };
            render(data);
            if (refreshSeconds) {
                setInterval(() => document.visibilityState === 'visible' && get(path(value, host)).then(render, () => {}), refreshSeconds * 1000);
            }
        });
    };

    const list = (items, empty) => {
        if (!items.length) return el('p', { class: 'muted' }, empty);
        const ul = el('ul');
        items.forEach((d) => {
            const li = el('li');
            const link = out(d.url);
            link.append(el('b', {}, `@${d.author ?? 'neighbor'} `), document.createTextNode(clip(d.body)));
            li.append(link);
            ul.append(li);
        });
        return ul;
    };

    mount('data-stoop-discussion', (_, host) => {
        const url = host.dataset.url || document.querySelector('link[rel=canonical]')?.href || location.href.split('#')[0];
        return `/api/discussions?url=${encodeURIComponent(url)}`;
    }, (box, data) => {
        box.setAttribute('aria-label', 'Neighbors on Stoop');
        box.append(el('div', { class: 'kicker' }, 'On Stoop'));
        box.append(el('h3', {}, data.count
            ? `${data.count} ${data.count === 1 ? 'neighbor is' : 'neighbors are'} talking about this`
            : 'Be the first neighbor to weigh in'));
        if (data.latest?.length) box.append(list(data.latest, ''));
        box.append(out(data.join, 'Join the conversation', 'cta'));
    });

    mount('data-stoop-neighborhood', (code) => `/api/embed/neighborhood/${encodeURIComponent(code)}`, (box, data) => {
        box.setAttribute('aria-label', `Neighbors at ${data.name} on Stoop`);
        box.append(el('div', { class: 'kicker' }, 'On Stoop'));
        box.append(el('h3', {}, `What neighbors at ${data.name} are saying`));
        box.append(list(data.dispatches, 'Quiet on the block so far.'));
        box.append(out(data.url, `More from ${data.name}`, 'cta'));
    });

    mount('data-stoop-place', (place) => `/api/embed/places/${encodeURIComponent(place)}`, (box, data) => {
        box.setAttribute('aria-label', `${data.name} on Stoop`);
        box.append(el('div', { class: 'kicker' }, 'On Stoop'));
        box.append(el('h3', {}, data.name));
        box.append(el('p', {}, `${data.been_count} ${data.been_count === 1 ? 'neighbor has' : 'neighbors have'} been here`));
        box.append(list(data.checkins, 'No check-ins yet.'));
        box.append(out(data.url, 'Been? Tell your neighbors', 'cta'));
    });

    mount('data-stoop-quote', (id) => `/api/embed/quotes/${encodeURIComponent(id)}`, (box, data) => {
        box.setAttribute('aria-label', 'Reader quote from Stoop');
        const quote = el('blockquote', { cite: data.url });
        quote.append(el('p', {}, `“${data.text}”`));
        box.append(quote);
        const by = el('p', { class: 'muted' });
        by.append(out(data.author_url, data.author_name), document.createTextNode(`${data.stop ? ` near ${data.stop}` : ''} on `), out(data.url, 'Stoop'));
        box.append(by);
    });

    mount('data-stoop-poll', (id) => `/api/embed/polls/${encodeURIComponent(id)}`, (box, data) => {
        box.setAttribute('aria-label', 'Reader poll from Stoop');
        box.append(el('div', { class: 'kicker' }, data.closed ? 'Reader poll · final results' : 'Reader poll · live results'));
        box.append(el('h3', {}, data.question));
        const ul = el('ul', { style: 'margin-bottom: 8px' });
        data.options.forEach((o) => {
            const li = el('li', { class: 'bar', style: 'border-top-style: solid' });
            li.append(el('i', { 'aria-hidden': 'true', style: `width: ${o.percent}%` }), el('span', {}, o.label), el('span', {}, `${o.percent}%`));
            ul.append(li);
        });
        box.append(ul);
        box.append(el('p', { class: 'muted' }, `${data.total} ${data.total === 1 ? 'vote' : 'votes'}`));
        if (!data.closed) box.append(out(data.url, 'Vote on Stoop', 'cta'));
    }, 30);

    mount('data-stoop-byline', (slug) => `/api/embed/authors/${encodeURIComponent(slug)}`, (box, data) => {
        box.setAttribute('aria-label', `About ${data.name}`);
        const row = el('div', { class: 'row' });
        if (data.photo_url) row.append(el('img', { class: 'photo', src: data.photo_url, alt: '' }));
        const who = el('div', { style: 'flex: 1' });
        const name = el('div');
        name.append(out(data.url, data.name), document.createTextNode(data.title ? ` · ${data.title}` : ''));
        who.append(name, el('div', { class: 'muted' }, `${data.followers} ${data.followers === 1 ? 'follower' : 'followers'} on Stoop`));
        row.append(who, out(data.url, 'Follow', 'cta'));
        box.append(row);
    });
})();
