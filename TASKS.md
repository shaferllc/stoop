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
- [x] Types: Dispatch, Ask DC, Poll, Happening, Been here, Crime & safety, Callout (staff only)
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
- [x] Half-smokes (Stoop’s pastéis): opt-in, 10 a day, reset at midnight DC time, no ranking effect
- [x] Badges and XP worked out from activity, with Metro ranks from Tourist to Mayor; toast when a badge unlocks
- [x] Ridership graph (activity graph) on profiles, streaks, embeddable SVG
- [x] On the scene (Going live): YouTube, Twitch or Instagram link for 1–12 hours, red ring and marker
- [x] House bots: @stoop answers questions (Claude when `ANTHROPIC_API_KEY` is set, knowledge-base lookup otherwise); @dc answers “hello DC”
- [x] New accounts start out following @stoop, and @stoop follows them back
<<<<<<< HEAD
- [x] @eats (place picks from the magazine’s lists), @weekend (Friday round-up with editors’ picks), @history (On this day in DC, staff-edited at Admin → History), @quizmaster (The Daily, streak leaders, cheers for shared results)
- [ ] Editors: check the seeded @history entries (marked VERIFY) so they start posting
- [ ] A public-stats opt-out for the quiz streak leaderboard (everyone who plays is listed now, by username)
- [ ] Ticketed events: a real field on events and Happening posts (@weekend guesses from the word “ticket”)
=======
- [x] City bots: @metro (major WMATA incidents once each, quiet 10pm–6am except closures and suspensions; “@metro next <station>”), @bikeshare, @weather (morning forecast, NWS alerts, Snow Day suggestion to staff), @cherry (NPS bloom stages in season, links an open Peak Bloom contest, asks staff to settle it at peak), @311 (request-type guide and a Sunday summary near busy stops)
- [ ] @cherry parses the NPS Bloom Watch page’s table; switch to a feed if NPS publishes one
- [ ] @311’s weekly summary uses a fixed list of busy stops; rank by ridership or Stoop activity if it matters
>>>>>>> worktree-agent-ad66da2e7879c6f34

## Notifications
- [x] Replies, quotes, mentions, follows and moderation outcomes
- [x] Grouped by thread and by day; filters; follow back; unread count
- [ ] Decide on email notifications (see open questions)

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
- [ ] Tune the OpenAI thresholds after a few weeks of real posts (count how many held posts moderators approve)
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
- [ ] Push notifications and the installable app need HTTPS; try them once the site is served over HTTPS

## Making money
- [x] Claimed places: owners request, staff approve, owners post as the business
- [x] Sponsored stops and lines, labeled, with view and click counts
- [x] Sponsored callouts (staff only), labeled
- [ ] Ticketed Happening posts
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
- [ ] Roles finer than “staff” (moderator vs. editor vs. sales)

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
- [ ] Ward and neighborhood data for stations, for a ward-explorer badge and ANC-specific meeting links
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
