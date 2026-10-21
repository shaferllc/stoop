# Stoop task list

Built from [docs/DEVNULL_FEATURES.md](docs/DEVNULL_FEATURES.md), which lists everything /dev/null does and how each feature maps to Stoop.

`[x]` means built. Most items are covered by `tests/Feature`; the browser-only parts (typing indicator, “Seen” receipts, hover cards, keyboard shortcuts, swipe, the installable app, the composer sheet) were checked by hand, not by tests. Live Reverb delivery, push delivery, the Turnstile widget, a real article feed, the subscription webhook from the real system and how the email looks in mail clients are built and tested with fakes only. `[ ]` means not done yet.

## Accounts
- [x] Email sign-up and log-in, password reset, email verification, 2FA, passkeys (Livewire starter kit / Fortify)
- [x] Username at sign-up; profile settings for NATIVE, year moved to DC, up to 3 home stops, translation language
- [x] Badges: STAFF, NATIVE, TRANSPLANT ’YY, SUBSCRIBER, HOUSE ACCOUNT
- [x] Verified email required to post
- [x] Subscription gate, off by default (`STOOP_REQUIRE_SUBSCRIPTION=true` once `subscribed_until` is filled from the magazine’s subscriber system)
- [ ] Connect `subscribed_until` to the real subscription system

## Dispatches
- [x] Types: Dispatch, Ask DC, Poll, Happening, Been here, Crime & safety, Lost & found, Callout (staff only)
- [x] Line and Metro stop on every dispatch; 3–500 characters; ⌘/Ctrl+Enter to post; 10 a minute
- [x] One shared posting action (`app/Actions/FileDispatch.php`) for the website, API and terminal
- [x] Replies, threads, quotes
- [x] Polls: 2–4 options, 1 hour to 7 days, one vote each, results after voting or closing
- [x] @mentions with suggestions (thread people first, then people you follow) and notifications
- [x] Show more on long posts
- [x] Photos: up to 3, 10 MB, alt text, re-encoded so GPS and other EXIF data is never stored
- [x] Link cards (fetched with private-address and redirect checks; no remote images shown)
- [x] Scheduling up to 30 days ahead, 10 at a time
- [x] Delete with 30-second undo; tombstones keep other people’s replies
- [x] Pin one dispatch to your profile
- [x] Share images (Open Graph PNGs) for dispatches and profiles
- [x] Never editable after posting

## Feeds and navigation
- [x] Following, Rush Hour, Latest, Near me; last feed remembered; swipe between feeds on phones
- [x] Rush Hour score halves every 3 hours (/dev/null’s Trending curve)
- [x] “N new dispatches” button instead of shifting the feed (checks every 30 seconds)
- [x] Filters by line and stop; stop pages
- [x] Who to follow (sidebar and empty Following feed)
- [x] Hover cards on names
- [x] Keyboard shortcuts: j/k, o, c, /, g h/n/s/p, ? for help
- [ ] Count distinct people in the Rush Hour score (it counts actions now)
- [ ] Store the Rush Hour score and refresh it on a schedule once volume grows

## Engagement and fun
- [x] Follow, Save, Been here, Going
- [x] Happenings: start time for the calendar, Going/Interested RSVPs with the attendee list for the host, a week/month calendar filtered by line and stop (city events alongside), .ics per happening and a private calendar feed link
- [x] Lost & found: pet or item, lost or found, last-seen stop, photo; no phone numbers or emails (replies and messages only); the poster marks it Reunited; board filtered by stop, line and kind
- [ ] Old Happenings with only a free-text time ("Tonight · 8pm") aren't on the calendar; hosts can add a start time from the post
- [ ] Lost & found: strangers can only message the poster if the poster has message requests on; consider opening requests for Lost & found replies
- [x] Half-smokes (Stoop’s pastéis): opt-in, 10 a day, reset at midnight DC time, no ranking effect
- [x] Badges and XP worked out from activity, with Metro ranks from Tourist to Mayor; toast when a badge unlocks
- [x] Ridership graph (activity graph) on profiles, streaks, embeddable SVG
- [x] On the scene (Going live): YouTube, Twitch or Instagram link for 1–12 hours, red ring and marker
- [x] House bots: @stoop answers questions (Claude when `ANTHROPIC_API_KEY` is set, knowledge-base lookup otherwise); @dc answers “hello DC”
- [x] New accounts start out following @stoop, and @stoop follows them back
- [x] @eats (place picks from the magazine’s lists), @weekend (Friday round-up with editors’ picks), @history (On this day in DC, staff-edited at Admin → History), @quizmaster (The Daily, streak leaders, cheers for shared results)
- [ ] Editors: check the seeded @history entries (marked VERIFY) so they start posting
- [ ] A public-stats opt-out for the quiz streak leaderboard (everyone who plays is listed now, by username)
- [ ] Ticketed events: a real field on events and Happening posts (@weekend guesses from the word “ticket”)
- [x] City bots: @metro (major WMATA incidents once each, quiet 10pm–6am except closures and suspensions; “@metro next <station>”), @bikeshare, @weather (morning forecast, NWS alerts, Snow Day suggestion to staff), @cherry (NPS bloom stages in season, links an open Peak Bloom contest, asks staff to settle it at peak), @311 (request-type guide and a Sunday summary near busy stops)
- [ ] @cherry parses the NPS Bloom Watch page’s table; switch to a feed if NPS publishes one
- [ ] @311’s weekly summary uses a fixed list of busy stops; rank by ridership or Stoop activity if it matters
- [x] @ask: every 30 minutes, replies once to Ask DC questions 2–24 hours old with no replies, linking up to 3 similar answered questions (full-text index); Claude writes one framing sentence when a key is set; skips safety and medical questions and anything without a close match
- [x] @welcome: one in-app welcome notification per new account (on its first post, or the 5-minute sweep after it accepts the rules) with 3 tips for its home stops and 3 active neighbors to follow; recorded in `bot_welcomes`
- [x] @recap: Sundays 9:00 DC time, “Your stop this week” for the 5 busiest stops with the 3 best approved dispatches (replies plus half-smokes) and the stop’s mayor
- [x] @translate: reply “@translate [to Spanish]” in a thread to translate the parent dispatch (any `Translator::LANGUAGES` language; defaults to your translation language)
- [x] @calm: one neutral reminder on a pile-on-flagged thread once its reply pause ends; never acts, once per thread
- [ ] @ask: search by meaning (embeddings) instead of shared keywords
- [ ] Reserve bot usernames (ask, welcome, recap, translate, calm) at sign-up: `Bots::account` returns an existing person with that username

## Notifications
- [x] Replies, quotes, mentions, follows and moderation outcomes
- [x] Grouped by thread and by day; filters; follow back; unread count
- [x] Email for timeouts, bans and appeal decisions (on by default, Settings → Safety turns it off); daily email to admins when items have waited over a day (`stoop:admin-digest`)
- [ ] Decide on email for everything else (see open questions)

## Messages (/dev/null “Remotes”)
- [x] One-to-one messages with people who follow you; optional message requests
- [x] Group chats up to 100, admins can rename, add and remove
- [x] Encrypted at rest, up to 2,000 characters, can’t be edited or deleted
- [x] Private photos served only to the conversation’s members
- [x] Replies, reactions (up to 12 per person per message), read receipts (both sides opt in), typing indicator, archive
- [x] Block from a conversation; report a message; timeouts stop sending
- [ ] Real-time delivery with Reverb instead of polling every 5 seconds

## Safety and moderation
- [x] Rules prompt before your first post; public rules page
- [x] “Would you say this to a neighbor?” nudge (website and API)
- [x] Crime & safety form that rejects race- or gender-only descriptions of a person
- [x] Held-pattern screening (`config/stoop.php`, empty by default)
- [x] Report → hidden for you at once, hidden for everyone at 3 reports
- [x] Mute and block, with a Safety settings page to undo them
- [x] Reply limits (people you follow) and hiding replies on your own threads
- [x] Pile-on detection: pauses replies for an hour and flags the thread
- [x] Moderation queue: approve, remove with a reason sent to the author, penalty ladder (warning, timeouts of 1h/1d/3d/7d/30d, ban)
- [x] Ban page; bans and timeouts survive account deletion (kept by a hash of the email)
- [x] Appeals, decided by a different moderator
- [x] Reported messages queue with context
- [x] Public monthly transparency report
- [x] OpenAI’s free moderation endpoint checks every new dispatch first (text and photos) and holds hate, harassment and threats, with the scores shown to moderators
- [x] Claude checks every new dispatch before it goes public (when `ANTHROPIC_API_KEY` is set); a failed check never approves
- [x] Link cards check IPv4 and IPv6 and pin the checked address, so DNS rebinding can’t reach internal hosts
- [x] Cloudflare Turnstile on sign-up (when keys are set)
- [x] New accounts: no message requests for 3 days, 20 posts a day for the first week
- [x] Privacy policy and terms (drafts, marked for legal review)
- [ ] Legal review of the privacy policy and terms
- [x] Threshold insights (Admin → Insights and the Mac app): per OpenAI category, held posts moderators approved vs removed in 30 days, with suggested changes from simple rules. Read-only
- [ ] Tune the OpenAI thresholds after a few weeks of real posts, using Admin → Insights
- [x] Watch phrases for coded and local hate (Admin → Insights and the Mac app): active phrases hold new posts with the meaning shown to moderators; seeded with labeled examples, the broad ones off
- [x] Claude takes a second look at posts OpenAI scored borderline for hate or harassment (half the hold threshold up to it) and can only hold them
- [x] Moderator help on every queue item: Claude’s one-line read, likely rule, suggested action and penalty (never a ban) and confidence, cached; “Accept suggestion” / S fills in the remove form or approves
- [x] Past decisions on similar posts (approved/removed counts and 3 examples) from the search index
- [x] Person brief on the admin person page (Claude, cached an hour; plain facts without a key) and appeal notes (what supports or contradicts the appeal)
- [x] Posts with high hate, harassment, sexual or violence scores, or a watch phrase, are blurred in the queue until clicked
- [ ] Show the borderline OpenAI scores on posts that weren't held (only flagged scores are stored today)
- [ ] Find similar past decisions by meaning (embeddings); it's keyword overlap for now
- [ ] Run the OpenAI check on reported private messages, to sort the moderation queue by severity
- [ ] Moderator training, rotation and an outside advisory group (people, not code)

## Platform
- [x] Search with stemming across dispatches and replies, plus people and stations; recent searches stay in the browser
- [x] Translation into the reader’s language (needs `ANTHROPIC_API_KEY`), cached 7 days
- [x] Public read API, posting API with personal tokens, terminal script at `/dispatch`
- [x] Atom feeds: latest, per line, per stop, per person
- [x] Article discussion counts for the magazine (`/api/discussions?url=…`)
- [x] Live Metro delays in the sidebar (needs `WMATA_API_KEY`)
- [x] Installable app (manifest, icons, offline page)
- [x] Data export (JSON) and account deletion that erases everything except penalties
- [x] Operations page for staff: activity, queue sizes, failed jobs
- [x] Catch me up on stop pages and line feeds: Claude sums up the last 24 hours of approved posts in 3–5 bullets (cached 30 minutes, rebuilt if a post in it is removed); the top 5 posts without a key
- [x] Composer suggestions: topic, Ask DC and station chips from keywords as you type; Claude refines on Suggest or a pause (with a key); never applied for you
- [ ] Search by meaning (embeddings); search is keyword-based with stemming for now
- [ ] Move link-card fetching to a queued job if posting feels slow
- [ ] Turn on Claude refusal fallbacks for the bot and translation once the PHP SDK documents them (a declined request falls back to the keyword answer or no translation today)
- [ ] Run the scheduler in production (`php artisan schedule:run` every minute) so scheduled dispatches publish and deleted ones are erased

## Places and the magazine
- [x] Place pages with Been here counts, photos and the magazine’s blurb, rank and review link
- [x] Tag a place in any dispatch; Been here posts require one
- [x] Admin CSV import of the magazine’s lists (re-importing a list updates it)
- [x] Article auto-posting from the site’s RSS/Atom feed (`STOOP_MAGAZINE_FEED`), on the matching line, never twice
- [x] Discussion embed for article pages (`<div data-stoop-discussion></div>` + `/embed.js`), with “Join the conversation” prefilling the composer
- [x] Subscription webhook from the magazine’s system (`POST /api/subscriptions`, HMAC-signed); applies to people who sign up later too
- [x] Weekly email: best dispatches, poll results, Talk of the Town, where people went; opt-in, one-click unsubscribe, admin preview and test send
- [x] Neighborhood and place boxes for articles (`data-stoop-neighborhood`, `data-stoop-place`), from a cached, throttled JSON API open only to the magazine's origins
- [x] Reader quotes: editors request, authors allow or decline (14-day expiry), quote card embed and oEmbed-style JSON, consent log
- [x] Reader polls for articles, posted by @magazine, with live results in the embed (voting links out to Stoop)
- [x] Writers from article bylines: author pages with discussion counts, follow for new-story notifications, byline embed, “By …” credit on @magazine posts, staff-editable title/photo/linked account, backfill command
- [ ] Run `php artisan stoop:backfill-authors` once in production
- [ ] Check the real feed's byline format (`dc:creator`) and article pages' `<meta name="author">` against the parser
- [ ] Point `STOOP_MAGAZINE_FEED` and the subscription system at the real ones
- [x] Article cards: magazine posts show the title, section, the feed's image (only from the magazine's own domain) and a Discuss count
- [x] Ask the editor (Admin → Editorial): an editor's thread with a start and end time; readers ask and upvote questions, the editor's answers sit under each one
- [x] Best of Washington reader vote (Admin → Editorial): categories of nominated places, one vote per category, results after it closes, results CSV for the magazine
- [ ] A public list of ballots (each ballot is reachable from its link only; announce it with a callout)
- [ ] Push notifications and the installable app need HTTPS; try them once the site is served over HTTPS

## Making money
- [x] Claimed places: owners request, staff approve, owners post as the business
- [x] Sponsored stops and lines, labeled, with view and click counts
- [x] Sponsored callouts (staff only), labeled
- [ ] Ticketed Happening posts
- [x] Reader insights for advertisers (Admin → Insights): posts, people, top topics, sentiment per station and line, top Been here places, rising keywords, games; any cell from fewer than 10 people shows “<10”; CSV and printable (PDF) views; optional Claude summary from the aggregates only (needs `ANTHROPIC_API_KEY`)
- [x] Open house posts on the Green line (block, date and time, price, link, labeled listing agent); staff mark them sponsored in Admin → Real estate
- [ ] Give reader insights to a sales role once roles exist (staff only for now); add neighborhoods once stations have them
- [ ] Ticketed or paid open house placements (sponsored is a label only)
- [x] Ticketed Happening posts: verified place owners and staff add a price and an outside ticket link, labeled Ticketed; “Get tickets” click-throughs are counted
- [x] Featured Happenings (staff), labeled, top of their day on the calendar
- [x] Owner deals: one running deal per claimed place, on the place page and labeled at the top of its stop feed
- [x] Readers’ picks report for editors (Admin → Readers’ picks): Been here check-ins, posts and word-count sentiment per place on a list, with CSV export
- [ ] Ticket sales through Stripe (Checkout + Connect payouts to venues) instead of an outside link
- [ ] Moderation queue for owner deals (they skip the dispatch pipeline; only the swearing filter runs)
- [ ] Aggregated reader-insights report (no individual data)
- [ ] Self-serve sponsorship booking and billing

## Admin
- [x] Overview with what needs attention, activity numbers and failed jobs
- [x] People: search, verify, staff access, subscription date, notes, penalties, history, delete
- [x] Places, list imports and claims
- [x] Sponsorships with stats
- [x] Content: article feed status, weekly email, callouts, scheduled posts
- [x] Audit log of every staff action
- [x] Keyboard-driven moderation queue (j/k, a, r, o)
- [x] Roles: moderator (queue, appeals, people, spam), editor (content, quiz, moments, house bots), sales (sponsorships, places), admin (everything, plus maintenance and roles); existing staff became admins; set in Admin → Roles; the desktop app hides sections a role doesn’t open
- [x] Spam rings: near-identical posts across accounts, bursts of new accounts in one thread, the same link domain from new accounts, new accounts messaging many people; checked every 10 minutes (`stoop:detect-spam`) or on demand; acting on a cluster hides its posts and times out its members (never bans)
- [x] Sign-up risk score (throwaway email domain, several sign-ups from one network in an hour by salted IP hash, Turnstile, random-looking username), staff only; risky new accounts’ first 3 posts wait for a moderator
- [x] “Is this a bot or a spam ring?” on a person’s admin page: Claude over recent activity, or the built-in signals without `ANTHROPIC_API_KEY`; a suggestion only
- [x] House bot console: recent @stoop and @dc answers, mark wrong, post a correction as the bot; @stoop’s knowledge base is editable (seeded from `resources/knowledge/stoop.md`)
- [x] Activity: live feed of posts, sign-ups, reports and removals, and 30-day charts (web and desktop)
- [x] Bulk verify, timeout and note in People (web and desktop)
- [ ] Tune the spam detector thresholds (`app/Support/SpamRings.php`) and the risk threshold (`signup_risk_hold`) on real traffic

## Playing the city
- [x] All 98 Metro stations with strip maps on profiles, line badges, Crossed the River, DMV, The Whole System
- [x] Stop mayors (most helpful in 30 days) on stop pages and profiles
- [x] The Daily quiz: 5 DC questions a day, shareable Metro-color result, streaks; editors manage the bank in Admin → Quiz
- [x] Single-Tracking pass: one missed day a week doesn’t break a posting or quiz streak
- [x] Weekly neighborhood rivalries with crowns and results in the weekly email; line standings
- [x] Best Answer on Ask DC and Know-It-All points
- [x] Passports and stamps for the magazine’s lists, with a stamp list for magazine shout-outs
- [x] Guess-the-date contests (Peak Bloom), Snow Day mode with a snow map, game-day picks, city events with ratings, Stoop Sits meetups with check-in codes (Admin → Moments)
- [x] Station pages: next trains (needs `WMATA_API_KEY`), Capital Bikeshare docks nearby (live, no key), DC 311 and ANC links
- [x] Glossary hovers for local terms, mumbo sauce reaction, Go-Go Friday thread, Stand Right badge with confetti
- [x] Recent home sales near each station (median and count over 90 days, 5 latest by block) from DC’s public property records, imported nightly (`stoop:import-home-sales`); @homes posts a weekly roundup for the 3 busiest stations
- [ ] Condo unit sales: DC GIS's owner polygons cover lots, so some condo sales may be missing
- [x] Metro Bingo: weekly 5×5 card per person, squares mark themselves from activity or by hand with a post as proof; badge and XP for a bingo
- [x] Seasonal scavenger hunts (Admin → Hunts): clues tied to stations or places, optional code words, passport stamps per step, leaderboard, Treasure Hunter badge
- [x] Monthly Line Cup: lines score their riders’ helpfulness points at their stations; the winning line’s regulars get the Line Cup crown badge
- [ ] Bingo only counts weeks where at least one square was marked by hand (all-automatic weeks show a bingo but don’t award it)
- [x] Ward and ANC for every DC station (`config/neighborhoods.php`), shown on stop pages with the ANC's own meetings page on oanc.dc.gov; Ward Explorer badge for posting in all 8 wards
- [ ] Verify the ward/ANC table before launch (looked up from DC GIS Ward 2022 and ANC 2023 boundaries by station location) and after any redistricting
- [x] Nearby DC 311 requests on DC stop pages (DC GIS open data, last 90 days, no key; `STOOP_DC_311=false` turns it off) and a Pepco outage map link (no scraping)
- [x] ANC meeting summaries (Admin → ANC): staff paste notes, Claude drafts, staff edit and publish from @stoop at every station in the ward
- [ ] Decide whether ANC summaries should post once per ward instead of once per station (Ward 2 gets 12 identical posts in Latest)
- [ ] Grow the quiz bank from the magazine’s archive
- [ ] Real prizes and fulfillment for contests and passport stamps

## Real-time, mobile and design
- [x] Reverb: new posts, messages and notifications arrive without waiting; pages still check every so often if Reverb is down
- [x] Push notifications for replies, mentions, follows and messages (VAPID keys in `.env`)
- [x] Phone layout: bottom tab bar, composer as a sheet
- [x] Stoop-steps logo and app icons, icon buttons, WMATA-style station markers, Metro-line threads, empty states, half-smoke and badge artwork
- [ ] A real designer’s pass on the logo, illustrations and type

## Open questions
- Can guests read Stoop, or does the magazine paywall cover it?
- Email notifications, or in-app only? /dev/null deliberately sends none.
- Who owns moderation: the audience team, editorial, or a contractor?
- /dev/null’s composer.json and README say MIT, but the repo has no LICENSE file. Stoop copies ideas and limits only, no code.
