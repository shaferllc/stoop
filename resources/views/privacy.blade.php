<x-layouts::stoop title="Privacy policy">
    <article class="rounded-xl border border-rule bg-card p-6 [&_h2]:mt-6 [&_h2]:font-display [&_h2]:text-xl [&_h2]:font-bold [&_li]:mt-1 [&_p]:mt-2 [&_p]:max-w-prose [&_ul]:mt-2 [&_ul]:max-w-prose [&_ul]:list-disc [&_ul]:pl-5">
        <p class="rounded-lg bg-chip px-3 py-2 text-sm">Draft for legal review. Replace the bracketed parts before launch.</p>
        <h1 class="mt-4 font-display text-3xl font-bold">Privacy policy</h1>
        <p>Stoop is run by [the magazine’s legal name]. This page says what we collect, why, and what you can do about it. We collect only what a feature needs.</p>

        <h2>What we store</h2>
        <ul>
            <li><b>Your account:</b> name, username, email, password (hashed, never readable), and the profile choices you make, like your stations and whether you grew up here.</li>
            <li><b>What you post:</b> dispatches, replies, photos, polls and votes, and your Saves, Been here and Going taps. Photos are re-encoded when you upload them, which removes location and camera data.</li>
            <li><b>Private messages:</b> stored encrypted. Staff read a message only when someone reports it, and then only that message and a few around it.</li>
            <li><b>Safety records:</b> reports, moderation decisions and appeals.</li>
            <li><b>Technical logs:</b> IP addresses and browser details for security and rate limits, kept for [30] days.</li>
        </ul>

        <h2>What we don’t do</h2>
        <ul>
            <li>We don’t sell your data or share it with advertisers.</li>
            <li>We never store your exact location. Posts show a Metro station or neighborhood, which you choose.</li>
            <li>Sponsors get totals (views and clicks), never who you are.</li>
        </ul>

        <h2>Services we use</h2>
        <ul>
            <li><b>Anthropic (Claude):</b> when it’s turned on, posts are checked against the house rules before they go up, and posts you ask to translate are sent for translation. Anthropic doesn’t use this data to train its models.</li>
            <li><b>Cloudflare Turnstile:</b> the check on the sign-up form.</li>
            <li><b>WMATA:</b> we fetch Metro delays. Nothing about you is sent.</li>
            <li>[Email provider], for sign-in emails and the weekly email if you turn it on.</li>
        </ul>

        <h2>Your choices</h2>
        <ul>
            <li><b>Download your data</b> any time from Settings → Profile.</li>
            <li><b>Delete your account</b> from the same page. It erases your profile, posts, messages, follows and notifications. Posts other people replied to stay as an empty placeholder so their replies make sense.</li>
            <li>One exception: if you were in a timeout or banned, we keep a one-way hash of your email and the penalty, so deleting and rejoining doesn’t reset it.</li>
            <li>Turn off the weekly email, read receipts and message requests in Settings.</li>
        </ul>

        <h2>Contact</h2>
        <p>[privacy@magazine.example]. Residents of [states with privacy laws] have the rights those laws give them; write to us to use them.</p>
    </article>
</x-layouts::stoop>
