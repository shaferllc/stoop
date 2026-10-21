/*
 * Stoop discussion box for magazine article pages.
 *
 *   <div data-stoop-discussion></div>
 *   <script src="{{ route('embed') }}" async></script>
 *
 * It uses the page's canonical URL, or data-url="…" on the div. Styles live in a shadow
 * root so they can't clash with the article page.
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
        li b { font-weight: 600; }
        .cta { display: inline-block; background: #c8102e; color: #fff; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 14px; }
        @media (prefers-color-scheme: dark) {
            :host { color: #eceeed; }
            .box { background: #1b1e20; border-color: #2b2f31; }
            li { border-color: #2b2f31; }
        }
    `;

    const el = (tag, attrs = {}, text) => {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
        if (text !== undefined) node.textContent = text;
        return node;
    };

    document.querySelectorAll('[data-stoop-discussion]').forEach(async (host) => {
        if (host.shadowRoot) return;

        const url = host.dataset.url || document.querySelector('link[rel=canonical]')?.href || location.href.split('#')[0];
        let data;
        try {
            data = await fetch(`${base}/api/discussions?url=${encodeURIComponent(url)}`).then((r) => r.json());
        } catch {
            return;
        }

        const root = host.attachShadow({ mode: 'open' });
        root.append(el('style', {}, css));
        const box = el('div', { class: 'box' });
        box.append(el('div', { class: 'kicker' }, 'On Stoop'));
        box.append(el('h3', {}, data.count
            ? `${data.count} ${data.count === 1 ? 'neighbor is' : 'neighbors are'} talking about this`
            : 'Be the first neighbor to weigh in'));

        if (data.latest?.length) {
            const list = el('ul');
            data.latest.forEach((d) => {
                const item = el('li');
                const link = el('a', { href: d.url, target: '_blank', rel: 'noopener' });
                link.append(el('b', {}, `@${d.author ?? 'neighbor'} `), document.createTextNode(d.body.length > 160 ? d.body.slice(0, 160) + '…' : d.body));
                item.append(link);
                list.append(item);
            });
            box.append(list);
        }

        box.append(el('a', { class: 'cta', href: data.join, target: '_blank', rel: 'noopener' }, 'Join the conversation'));
        root.append(box);
    });
})();
