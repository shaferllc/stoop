# Stoop task list

Built from [docs/DEVNULL_FEATURES.md](docs/DEVNULL_FEATURES.md), which lists everything /dev/null does and how each feature maps to Stoop.

`[x]` means built and covered by `tests/Feature/StoopTest.php`. `[ ]` means not started.

## Phase 1: core (built)

### Accounts
- [x] Email sign-up and log-in, password reset, email verification, 2FA, passkeys (Livewire starter kit / Fortify)
- [x] Username at sign-up, letters, numbers and underscores only
- [x] Profile settings: NATIVE checkbox, year moved to DC, up to 3 home stops
- [x] Badges: STAFF, NATIVE, TRANSPLANT ’YY, SUBSCRIBER
- [ ] Tie sign-up to a real magazine subscription (posting requires an active subscriber; reading stays open)

### Dispatches
- [x] Types: Dispatch, Ask DC, Poll, Happening, Been here, Callout (staff only)
- [x] Line (topic) on every dispatch, optional Metro stop (WMATA station code)
- [x] 3–500 characters, live counter, ⌘/Ctrl+Enter to post
- [x] Rate limit: 10 posts a minute per person
- [x] Replies and thread page (`/d/{id}`)
- [x] Quotes (Quote button loads the original into the composer)
- [x] Polls with 2–4 options of up to 50 characters, one vote per person, results after voting
- [x] Delete your own dispatch (staff can delete any)
- [x] Never editable after posting
- [ ] Poll durations (1h / 1d / 3d / 7d) and closing
- [ ] Happening with a real start and end time that drops off the feed after it ends
- [ ] 30-second undo after delete; tombstone so replies survive
- [ ] Fold long posts behind “Show more”
- [ ] Images: up to 3, alt text, 10 MB each
- [ ] Link cards
- [ ] @mentions with suggestions and notifications
- [ ] Draft preview and stashed drafts
- [ ] Scheduled dispatches

### Feeds
- [x] Following, Rush Hour, Latest, Near me
- [x] Rush Hour score: engagement weighed against age, halving every 3 hours (/dev/null’s Trending curve)
- [x] Filter by line, by stop, and by search text
- [x] Guests can read Rush Hour, Latest, threads and profiles
- [x] Load more, 20 at a time
- [ ] Count distinct people in the Rush Hour score (it currently counts actions)
- [ ] Store the Rush Hour score and refresh it on a schedule once volume grows
- [ ] “N new dispatches” prompt instead of jumping the feed (poll every 30 s)
- [ ] Empty Following feed suggests people to follow
- [ ] Remember the last feed opened
- [ ] Stop pages (`/stops/E04`) and station search

### Engagement
- [x] Follow and unfollow, with follower and following counts
- [x] Save (private bookmarks) and the Saved page
- [x] Been here and Going taps with counts
- [ ] Pin one dispatch to your profile
- [ ] Who-to-follow suggestions
- [ ] Hover cards on names

### Notifications
- [x] In-app notifications for replies, quotes and follows
- [x] Unread count in the sidebar; opening the page marks them read
- [ ] Group them (follows per day, replies per thread)
- [ ] Filters by type
- [ ] Moderation outcome notices
- [ ] Decide on email (see open questions)

### Safety and moderation
- [x] Pre-post nudge: “Would you say this to a neighbor?” on heated wording; post again to send
- [x] Held-pattern screening: matching posts are held for review instead of published (`config/stoop.php`, list is empty by default)
- [x] Report with a reason and an optional note; the post is hidden for the reporter right away
- [x] Reports from 3 different people hide a post for everyone until review
- [x] Mute (you stop seeing them) and block (neither of you sees the other; follows removed)
- [x] Muted accounts can’t post until the mute ends
- [x] Moderation queue for staff: approve, remove, mute the author for 24 hours or 7 days
- [x] Public house rules page (`/rules`)
- [ ] Replace the keyword lists with a moderation model; hold anything it isn’t sure about
- [ ] Structured crime and safety form: what, where, when, and no race-only descriptions
- [ ] Penalty ladder tracking: warnings, strikes, permanent bans with a ban page
- [ ] Penalties survive account deletion (kept by subscriber id)
- [ ] Appeals, reviewed by a different moderator
- [ ] Rejection reason sent to the author
- [ ] Safety settings page to list and undo mutes and blocks
- [ ] Reply limits (followers only) and hide replies on your own posts
- [ ] Pile-on detection: slow replies and alert a moderator on sudden spikes
- [ ] Public monthly transparency report
- [ ] Signup screening (verified email plus an active subscription)

## Phase 2: DMs (/dev/null “Remotes”)
- [ ] One-to-one messages, encrypted at rest, up to 2,000 characters
- [ ] Contact policy: message people who follow you; optional message requests
- [ ] Reactions, read receipts, archive
- [ ] Group chats up to 100 people
- [ ] Report a message

## Phase 3: platform
- [ ] Data export (JSON) and account deletion that erases everything
- [ ] Public read API and Atom feeds per line and per stop
- [ ] Open Graph images for dispatches and profiles
- [ ] Installable PWA
- [ ] Keyboard shortcuts beyond `/` for search (`c` to compose, `j`/`k` to move)
- [ ] Live Metro delays in the sidebar (WMATA API)
- [ ] Magazine story cards: attach an article and show a discussion count on the article page
- [ ] Translation
- [ ] Semantic search (search by meaning, not exact words)
- [ ] Admin operations page: failed jobs, traffic, product metrics

## Phase 4: making money
- [ ] Claimed places for restaurants and shops
- [ ] Sponsored stops, clearly labeled
- [ ] Sponsored callouts, approved by editors
- [ ] Ticketed Happening posts
- [ ] Aggregated reader-insights report (no individual data)

## Dropped from /dev/null
Pastéis, achievements and XP, activity graphs and README embeds, terminal and GitHub Action posting, the @world and Tom bots, code blocks, GitHub issue links, Going live, GitHub sign-in and follow import.

## Open questions
- Can guests read Stoop, or does the magazine paywall cover it?
- Email notifications or in-app only? /dev/null deliberately sends none.
- Who owns moderation: audience team, editorial, or a contractor?
- /dev/null is MIT according to its composer.json and README, but the repo has no LICENSE file. Stoop copies ideas and limits only, no code.
