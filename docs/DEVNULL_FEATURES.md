# /dev/null feature inventory, mapped to Stoop

Source: the cloned /dev/null repo (`PRODUCT.md`, `KNOWLEDGE_BASE.md` Features section, `routes/web.php`, `config/signup.php`, rate limiters in `app/Providers/AppServiceProvider.php`). /dev/null is a developer social network ("commits" in "logs") built on Laravel + Inertia + React. Stoop is a DC local social network for a city magazine on Laravel 13 + Livewire 4 + Tailwind 4. This file is the source of truth for the Stoop task list.

## License

- `composer.json` declares `"license": "MIT"`. The README says the same ("Licensed under MIT, as declared in `composer.json`"), and notes it is based on Nuno Maduro's Laravel starter kit.
- **There is no LICENSE file in the repo root.** The MIT declaration lives only in `composer.json` and the README.
- What this means for us: MIT allows copying and changing the code if we keep the copyright and permission notice. Without a LICENSE file there is no copyright line to keep, so the declaration is weaker than usual. **Plan: reuse ideas, limits and behavior freely. Copying actual code is allowed under MIT, but the frontend is React/Inertia and Stoop is Livewire, so very little will port directly anyway.** If we copy any PHP (actions, queries, middleware, rate limiters), add a `THIRD_PARTY_NOTICES` entry crediting "/dev/null (nunomaduro/dev-null.sh), MIT" and have someone confirm the license with the upstream author first.

## Key product rules

| /dev/null rule | What it means there | Stoop |
| --- | --- | --- |
| An account is a GitHub account | Accounts only created from GitHub; name/avatar/bio/username always mirrored from GitHub, never editable on-site. Other sign-ins (Sign in with Apple) can only be a second way into an existing account. | **Adapt.** An account is a magazine subscriber account. Accounts are only created from a verified subscriber email (magic link or subscriber SSO). Profile is editable on Stoop (no GitHub to mirror), but the account must map 1:1 to a subscriber record. |
| Nothing is public before moderation | Content shows to others and notifies anyone only once approved. Pending/rejected content is visible only to author and moderators. Mutes and blocks filter everywhere: logs, threads, search, suggestions, notifications. | **Keep, strictly.** This is the core of Stoop's anti-hate-speech stance. |
| Commits are immutable | Create and delete, never edit. Delete has a short undo. Deleted posts leave an authorless tombstone so others' replies survive. | **Keep.** Dispatches cannot be edited. Also simplifies moderation (approved text can't be swapped afterward) and search embeddings. |
| Store as little as possible | Assume the DB leaked. No data without a feature that needs it; private content encrypted; no secrets stored; everything about a person erased on account deletion and included in export. Exception: bans and timeouts are kept by GitHub id after deletion (id, reason, dates only) so delete-and-rejoin can't escape them. | **Keep.** Exception becomes: penalties (warnings, mutes, suspensions, bans) are kept by subscriber id / hashed email after deletion, holding only that key, reason and dates. Location ("Near me") must not be stored; use it per-request only. |
| Same rules on every surface | Web, mobile API, terminal, and Action share validation, moderation, visibility, rate limits. | **Keep.** Web, any future app API, email-to-post, and staff tools all go through one set of actions. |
| Deliberately excluded | Sign-up outside GitHub, editing commits, email or push notifications. | **Adapt.** Keep "no editing". Sign-up outside subscriber accounts stays excluded. Email notifications: likely needed for a magazine audience (digest, moderation outcomes, appeals); decide explicitly. Push: later. |

---

## 1. Commits (Stoop: dispatches)

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Post vocabulary | A post is a **commit**, a timeline is a **log**, in UI and code (`Commit` model, `LogController`, `/log/{log}`). | **Rename** to dispatch / feed. Model `Dispatch` with a `type` enum: dispatch, ask, poll, happening, been-here, staff callout. |
| Composer | Open composer or press `c`; submit with Commit or ⌘/Ctrl+Enter. | **Keep.** Add type picker (dispatch / ask / poll / happening / been-here; staff callout only for STAFF). |
| Length | Text-only posts 3–4,000 chars. Progress ring around avatar fills toward the limit. With an image, text is optional but max still applies. Hard raw cap 40,000 chars. | **Adapt.** Keep 3-char min and the ring; consider a lower max (e.g. 1,000–2,000) for a local feed. Keep a raw cap. |
| Folding | Lists fold posts longer than 280 chars or 8 lines behind **Show more**, without cutting through code. | **Keep** (280 chars / 8 lines). |
| Station tag | n/a | **Add.** Each dispatch can carry one Metro station code ("stop"). Required for been-here and happening; optional otherwise. |
| Line (topic) | n/a | **Add.** Each dispatch has one line: red=Food, orange=Going Out, blue=Culture, green=Real Estate, yellow=Ask DC, silver=MD/VA. Ask type defaults to yellow. |
| Submission rate limit | Commits, replies, quotes, scheduled, terminal and API share **10 attempts/min/user** (`ThrottleCommits`). | **Keep.** Consider also a daily cap for new accounts. |
| Moderation on submit | Posts may be pending; admins and authors with good reputation are auto-approved. | **Keep**, with Stoop auto screening in front (see Safety). |
| Code formatting | Inline code and fenced blocks with highlighting and copy button. | **Drop.** Not a dev audience. Keep plain text + emoji + links + mentions. |
| Emoji shortcodes | `:shortcode:` conversion outside code. | **Keep** (cheap). |
| Mentions | `@username` links to profiles, notifies after approval. Bare `@` suggests people you follow; in replies, direct recipient first, then root author, then thread participants; ↑/↓, Enter/Tab, Esc. Excludes self, muted, blocked. Emails and `@scope/pkg` are not mentions. | **Keep.** |
| `#123` issue expansion | Looks up issue/PR in configured repo, inserts GitHub URL. | **Drop.** No GitHub. Possible later: `#STOP` station autocomplete using the same pattern. |
| Draft preview | Eye button or ⌘/Ctrl+Shift+P renders the post; shows which link gets the card. Esc returns. | **Keep.** |
| Secret warning | Composer detects credentials; asks confirmation; can minimize to a shield icon; secret text not stashed. | **Adapt** into the **pre-post nudge**: detect slurs, dehumanizing language, doxxing (phone numbers, home addresses, plate numbers), and race-only suspect descriptions; ask the author to reconsider before submitting. Same UX: warning, can still edit, flagged text is not stashed locally. |
| Stashed drafts | Unfinished text, images, alt text kept in browser storage per user; not synced; cleared on publish/drop. | **Keep** (localStorage, per user). |
| Images | Up to **3** JPEG/PNG/WebP, **10 MB** each, max **6,000 px** per side, alt text up to **1,000 chars**. Picker, paste, drop. Re-encoded to WebP, max **1,600 px** wide, strips EXIF (location). Images + poll can't combine. Pending images visible only to author and admins. Photo viewer with next/prev, alt text, download. | **Keep all limits.** EXIF stripping is mandatory for a local network (home locations). Images also go through screening. |
| Links | Each link counts as max **23 chars** toward the limit. Prose URLs over **2,048 chars** rejected. Full URL stored, no redirector. Long URLs shortened visually. GitHub links shown as `owner/repo#123`. | **Keep** 23-char counting and 2,048 cap. **Adapt** special rendering: magazine article links show as the magazine's article card. **Drop** GitHub rendering. |
| Link cards | First link gets a card (title, description, image). Composer can pick another link or no card. | **Keep.** |
| Embedded players | Click-to-play YouTube, Vimeo, Loom, Twitch, Spotify, SoundCloud, Apple Music, Suno, CodePen, asciinema. Third-party content loads only after play. | **Adapt.** Keep click-to-play for YouTube, Vimeo, Spotify, Apple Music, SoundCloud. Drop CodePen, asciinema, Loom, Suno. |
| Replies & threads | Same limits, images, moderation, rate limit. Inline expansion, replies shown: 1/3/5/10/15/20/25 (default 3). Commit page shows ancestors + replies. Reply can't be quote, poll, or scheduled. Approved replies notify the direct parent author. | **Keep.** |
| Self-threads | Author's earliest self-reply continues the post as a thread; page reads it 10 at a time with `3/7` markers; elsewhere "Show thread". Stops at first reply you can't see. | **Keep** (useful for multi-part neighborhood reports). Low priority. |
| Quotes | New top-level post with commentary + card of the original. No preview, card choice, poll, or scheduling. Listed among original's replies and counted. Original author notified after approval. Deleted → `[deleted]`, inaccessible → `[unavailable]`. **No plain repost** (quoting forces you to add something). | **Keep**, including no plain repost. |
| Polls | Top-level only. **2–4** distinct options, **50 chars** each, durations **1h / 1d / 3d / 7d**. Voting opens at approval. One vote, can't change or withdraw. Author can't vote. Results visible to author and voters, everyone once closed. Individual votes never revealed. | **Keep as dispatch type `poll`.** Same rules. |
| Scheduling | Top-level only, future time within **30 days**, 5-minute steps, max **10 outstanding**. Counts against 10/min. Private scheduled page lists queued (cancelable) and submitted with outcome. Scheduler runs every minute; goes through normal moderation. No editing: cancel and reschedule. | **Adapt.** Keep for STAFF callouts and happenings. Optional for regular users. |
| Translation | "Translate" when detected language differs from reader's; language chosen in settings or browser default. DeepSeek via OpenCode translates text only; cached 7 days, keyed by SHA-256 of text; evicted on delete. 10 requests/min. Site-wide kill switch. Pending posts never translated. | **Adapt / later.** DC has large Spanish and Amharic speaking communities; nice-to-have. Keep the "never change the original" and kill-switch rules if built. |
| Delete + undo | Delete own post; **Undo for 30 seconds** restores it without resending notifications. Replies under it survive under an authorless `[deleted]` tombstone. Can't reply to a deleted post. | **Keep** (30s undo, tombstones). |
| Happening | n/a | **Add.** Dispatch type with start/end time and a stop; drops off "Rush Hour" after it ends. |
| Been here | n/a | **Add.** Dispatch type tied to a stop/place; also a one-tap "Been here" on any place-bearing dispatch (see Engagement). |
| Staff callout | n/a | **Add.** STAFF-only type, visually distinct, can be pinned to a feed. Still immutable. |
| Crime/safety post | n/a | **Add.** Structured form (what happened, where = stop/block, when, description fields). Description must include non-racial identifiers (clothing, height, vehicle, direction) if race is given; race-only descriptions rejected at validation. Always routed to human review. |

## 2. Logs (Stoop: feeds)

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Following | Your posts and those you follow, newest first; sign-in required. | **Keep** as **Following**. |
| Latest | Newest public posts sitewide. | **Keep** as **Latest**. |
| Trending | Hot score: count of **distinct other people** who engaged anywhere in the thread; a reply or quote counts **3×** a star; each person counted once; author excluded. Weighed against age so engagement must **double to keep up with a post 3 hours newer** (i.e. score ≈ engagement / 2^(age_hours/3)). Pastéis excluded. | **Rename to Rush Hour.** Keep the formula (distinct people, reply=3× save, author excluded, 3-hour half-life). Saves and been-here taps count as the 1× signal. |
| Near me | n/a | **Add.** Dispatches tagged with stops near the viewer's chosen home stop (or one-off browser location, never stored). |
| Line filter | n/a | **Add.** Filter any feed by line color. |
| Paging | Top-level posts (including quotes), not replies, **20 per page**, infinite scroll. Own pending posts shown to you. | **Keep.** |
| New-post polling | Following and Latest poll every **30 s** and show "N new commits" instead of moving content; mark where you left off. On gateway errors, keep content and retry on schedule. | **Keep** (Livewire `wire:poll.30s`). |
| Empty Following | Suggests 3 recent posts from people to follow. | **Keep.** |
| Filtering | Mutes and blocks filtered for signed-in viewers. | **Keep.** |
| Remember last feed | Last opened log remembered. | **Keep.** |
| Swipe between logs | Sideways swipe on touch. | **Keep** (low priority). |
| Back/forward restore | Restores expanded replies and scroll position. | **Keep** (Livewire navigate + scroll restore). |
| Guest landing | Guests land on Trending. | **Keep**: guests land on Rush Hour. Decide whether guests can read at all (paywall question for the magazine). |

## 3. Engagement

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Follow | Follow/unfollow anywhere; updates counts in place. Can't follow yourself or across a block. **30/min**. | **Keep.** |
| GitHub follow import | One-time import of GitHub connections already on site, both directions, up to 1,000 each way. | **Drop.** No GitHub. |
| Who to follow | Side column suggestions with first bio line and reason ("N people you follow follow them" / follower count); "Follow back". Toggle in Appearance. | **Adapt.** Reasons: shared home stop, followed by people you follow, staff picks. |
| Stars | Star others' visible posts; not your own; once per post; can notify author; unstar removes notification. **60/min**. Explicitly not a reading list. | **Rename / Adapt.** Stoop merges this into **Save** (private bookmark) plus **Been here** (public tap on place-bearing posts). Decide whether Save notifies the author; /dev/null keeps "appreciation" and "bookmark" separate. |
| Saved | Bookmark, private, newest-saved first, doesn't notify. KB says `/saved`, but `routes/web.php` only has `GET /starred` (`StarredCommitController`), so code and docs disagree. | **Keep** as Save at `/saved`. |
| Been here | n/a | **Add.** One tap per user per dispatch with a stop; count shown; feeds Rush Hour as a 1× signal. |
| Pin | Pin one own top-level post to top of profile; new pin replaces old. Replies can't be pinned. **20/min**. | **Keep.** |
| Pastéis | Opt-in mode; **10 per day**, reset **00:00 UTC**; multiple per post allowed; no self-award; no notification; no Trending effect. Livestream lore. | **Drop.** Lore-specific. If we want a fun local token later, design it fresh. |
| Achievements & XP | 13 badges (first post, code fence, 7/30/100-day streaks, 50/100 posts, starred 10, received 25 stars, 5 replies, SSH key, 100 pastéis, "luddite"). XP: 10/post, 15/streak day, 5/star received, 5/reply to others, 2/star given; luddite −100. Computed live, not stored. Toast + confetti. | **Drop** for v1. Engagement mechanics conflict with moderation goals. Stoop badges are identity badges instead (see Profiles). |

## 4. Profiles

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Profile page | `/@{username}`: identity, bio, counts, **Follows you** badge, activity graph. Commits and Replies tabs. Follower/following lists. Share via share sheet or copy link. Unavailable across a block. | **Keep** (`/@{username}`). |
| Identity source | Mirrored from GitHub; Edit on GitHub + Sync from GitHub (**1/min**); refreshed on sign-in; rename handling via `_renamed-<id>` placeholder; matched by GitHub id, never merged. | **Adapt.** Editable display name, avatar, bio, home stop on Stoop. Username unique, changeable with a cooldown. Accounts keyed by subscriber id. |
| Badges | Achievement badges (above). | **Replace** with **NATIVE**, **TRANSPLANT 'YY** (year moved to DC), **SUBSCRIBER**, **STAFF**. NATIVE/TRANSPLANT are self-declared; SUBSCRIBER and STAFF are system-set. |
| Lane color | One of 6 colors (Orange, Green, Violet, Teal, Rose, Ochre), custom, or auto; used around identity and graph. | **Adapt.** Let users pick a "home line" color instead. |
| Timezone | Optional shared timezone; timestamps show author's local time too. | **Drop.** Everyone is in DC. |
| Activity graph | Adapts to 24h/7d/30d/year; current and longest streak; hover highlights. | **Drop** for v1 (engagement mechanic). |
| README graph SVG | `/@{username}/graph.svg`, `?theme=light|dark|auto`, cached 1h, **60/min/IP**. | **Drop.** |
| Going live | YouTube link, 1–12 h (default 2); red ring + Live marker; thumbnail hover; plays on YouTube, never embedded; cleared within 5 min. Stores only platform, link, end time. | **Drop** for users. Possible later for STAFF live events. |
| Sponsors link | GitHub Sponsors link. | **Drop.** |
| Hover cards | `@user/preview`, **120/min** ("people" limiter). | **Keep.** |
| OG images | `/@{user}/og.png`, per-commit `og.png` social previews. | **Keep.** |

## 5. Remotes (Stoop: DMs)

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Vocabulary | Conversation = remote, send = push, read receipt = pulled, request = fetched → merged / dropped, archive = stash / pop, group = org of maintainers and contributors, HEAD = where you left off. | **Rename** to plain words: Messages, Requests (accept / decline), Archive, Group, Admin / Member. |
| Enable/disable | Toggle in settings; disabling keeps existing conversations, stops new ones. | **Keep.** |
| Contact policy | Default: you can message someone who **follows you**, both have it on, no block. Follow needed only for first message. Admins exempt. Bot has DMs off. | **Keep.** Strong default for a local network. |
| Message requests | Optional (off by default): first message outside policy waits in Fetched; recipient merges or drops; sender can't keep sending; recipient must accept before replying. | **Keep, on.** Gives a safe "everyone" option. |
| Messages | Up to **2,000 chars**, reply-to, link cards, up to 3 images (same limits), image makes text optional. **Can't edit or delete.** Mentions in DMs don't notify. **30 messages/min**, other DM endpoints **300/min**. | **Keep.** |
| Reactions | Any emoji, up to **12 different per person per message**; 3 quick reactions, picker, double-tap toggles ❤️. | **Keep.** |
| Typing indicators | Recent activity shown. | **Keep** (low priority). |
| Read receipts | On by default; shown only when both people have them on. | **Keep.** |
| Archive | Stash per person; pops back on new message. | **Keep.** |
| Dock | Desktop: inbox docked at bottom of every page. | **Later.** |
| Delivery | Polling every few seconds while open; no push. | **Keep** (Livewire poll); Reverb later. |
| Encryption | Bodies encrypted at rest with app key, not end-to-end. | **Keep** (Laravel `encrypted` cast). |
| Block from conversation | Menu in conversation. | **Keep.** |
| Groups (orgs) | Up to **100 people**; name up to **60 chars**. Members must follow the adder (unless admin), have DMs on, no block with anyone in group. Creator = maintainer; invitees = contributors. Maintainers rename, add/remove, change roles; can share existing history or only new messages. Last maintainer can't be demoted; if they leave, longest-standing member takes over. Empty group deleted. | **Keep, later phase** (building/block groups). Rename roles to Admin / Member. |
| DM moderation | Timeouts block sending DMs and creating/renaming/adding to groups. | **Keep**, plus a "Report message" action (not in /dev/null). |

## 6. Notifications

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Types | Follows, replies, quotes, mentions, stars, moderation rejections (approvals don't notify). | **Adapt.** Follows, replies, quotes, mentions, saves (if they notify), been-here (grouped), moderation outcomes, warnings/penalties, appeal decisions. |
| Polling | Unread indicator checks every **30 s**. | **Keep.** |
| Grouping | Stars per post, follows per day, replies and mentions per thread; expandable. | **Keep.** |
| Filters | Replies, Quotes, Stars, Mentions, Follows, Moderation; remembered; unread count follows filter. | **Keep.** |
| Mark read | Opening inbox marks all read. | **Keep.** |
| Follow back | From follower notification. | **Keep.** |
| Rules | Pending content doesn't notify until approved. Self-actions don't notify. Mentioning the parent author doesn't duplicate reply notice. Re-follow notifies only once. Deleted sources, mutes, blocks hide notifications; unstar removes star notification. Rejections show reason, never the moderator identity. | **Keep all.** |
| Channels | In-app only; no email, no push (deliberate). | **Adapt.** In-app first. Email for moderation outcomes and appeals is probably required; optional daily digest. |

## 7. Search

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Open | ⌘/Ctrl+K or `/`. | **Keep.** |
| People search | By name or username, **120/min**. | **Keep.** |
| Post search | Semantic (by meaning, embeddings) with an AI relevance check that filters nearest matches. Query **3–100 chars**. Covers replies. Guests allowed. **15/min** per user or IP. Respects visibility, mutes, blocks. | **Adapt.** Start with full-text (MySQL/Postgres FTS or Scout). Add filters by line and stop. Semantic later. Keep 3–100 chars and 15/min. |
| Recent searches | Last **3** distinct, browser-only, per account, cleared on sign-out/deletion; guests get none. | **Keep.** |
| Station search | n/a | **Add.** Search stops by name/code; stop page lists its dispatches. |

## 8. Safety and moderation

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Signup screening | GitHub account **≥7 days old** and **verified email** (configurable). Returning users not re-screened. | **Adapt.** Must be an active subscriber with verified email. Optional: new accounts start in a probation tier (all posts to human review for first N posts or days). |
| Status model | pending / approved / rejected. Pending and rejected visible only to author and moderators. Author sees a badge: "being checked automatically" vs "waiting for a moderator"; check mark when approved live. | **Keep.** |
| Auto screening | Automated checks can auto-approve (admins, good reputation) or reject clear violations. **A failed check never approves**; post stays pending. | **Keep and strengthen.** Run a hate-speech / harassment / doxxing classifier plus a slur list. Clear violations auto-reject; borderline go to human queue; crime/safety posts always to humans. Fail closed. |
| Pre-post nudge | Secret warning only (see Commits). | **Add** (see Commits row). |
| Commit rules gate | Before first post/reply/quote, composer shows rules on every attempt until "Got it, commit": no engagement bait, no low-effort, no AI-generated, no spam/self-promotion, no harassment/hate/private info; link to terms. | **Keep.** Rewrite for Stoop: no hate or harassment, no race-only suspect descriptions, no doxxing or home addresses, no spam/self-promotion, no engagement bait. |
| Mute | Silent; removes their posts from feeds, threads, search, suggestions; stops their notifications; follows stay; profile shows Muted badge; saved items stay. | **Keep.** |
| Block | Stops DMs, follows, replies, stars, bot replies; hides each other's posts while signed in; profile unavailable; removes follows both ways; unblock doesn't restore. Not a guest-visibility control. | **Keep.** |
| Safety settings | Settings → Safety lists and reverses mutes/blocks. | **Keep.** |
| Reporting | Visible, approved post or reply. Reasons: spam/misleading links, harassment or hate, harmful or private info, other; optional **500 chars**. Once per post, **5 reports/min**. **Does not hide content.** Reviewers don't see reporter identity. Dismiss or reject with reason. Reports survive deletion. | **Adapt: report → hide.** After a threshold (e.g. N distinct reporters, or 1 report on a "hate" reason from a trusted account), the dispatch is auto-hidden pending review. Add reasons: hate based on race/ethnicity/religion/etc., race-only description, doxxing, false crime report. Keep 500 chars, once per post, 5/min, reporter anonymity. Add report for DMs and profiles. |
| Moderator actions | Admins only. Approve, or reject with optional reason up to **280 chars** sent to author (moderator not named). | **Keep.** Moderators = magazine staff with a `moderator` role (not only site admins). |
| Timeout | **1h, 1d, 3d, 7d, 30d**, reason from the rules. Can read and react, can't post, reply, quote, schedule, DM, or manage groups on any surface; each attempt shows time left and reason. Scheduled posts wait. Can end early. Profile shows moderators the timeout count. Kept by GitHub account across deletion. Admins exempt. | **Adapt into penalty ladder:** warning → **24 h mute** (same as timeout) → **7-day suspension** → **ban**. Ladder auto-suggests the next step from history; moderator confirms. Keep "read and react only", per-attempt message, history count, persists across account deletion. |
| Ban | Reason required. Banned user redirected everywhere to a page explaining why; can only sign out or delete account; API refuses all but delete. Kept by GitHub account. Cancels scheduled posts. Admins exempt. | **Keep.** Keyed by subscriber id / hashed email. |
| Timeouts & bans page | Lists everyone in timeout and every ban; end/lift, including on deleted accounts. | **Keep.** |
| Appeals | Not present. | **Add.** Author can appeal a rejection or penalty once, with text; goes to a different moderator; outcome notified (in-app + email). |
| Transparency report | Not present. | **Add.** Public monthly page: posts submitted, auto-rejected, human-rejected, reports received, hides, warnings/mutes/suspensions/bans issued, appeals and overturn rate, median review time. Aggregates only. |
| Moderation of DMs | Timeouts block DMs; no DM reporting. | **Add** report-message. |

## 9. Bots

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Tom | Welcome bot; every new user starts as mutual follow. Tag @tom to ask about the platform; answers from knowledge base, public repo (top contributors, merged PRs, issues, stars, language bytes; 15-min cache), and community aggregates (member counts, 7/30-day signups, active posters; 5-min cache; country/language from public profile text; never lists of people). DMs off. Moves to @devnull-tom if the real @tom signs up. "Luddite" badge for unfollowing him. | **Adapt / later.** A staff-owned welcome account that follows new users is cheap: **Keep** that. An AI "ask the magazine" bot (answers from magazine archive) is a later phase; if built, keep the "aggregates only, never lists of people" rule. Drop the repo tools and the luddite gag. |
| World | Replies "Hello, {name}." to approved posts whose entire text is "hello world"; once per post. | **Drop.** |
| Bot handle squatting | Bots yield their handle to a real owner and move to a prefixed one. | **Drop** (we control usernames). Reserve staff/bot handles up front. |

## 10. Data

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Export | JSON download: profile, posts (including pending/rejected/deleted still stored), polls + own votes, stars, saved, follows, mutes, blocks, sent messages, notifications, SSH key fingerprints, devices, last-seen day, session metadata. Excludes others' content, message images, reactions, filed reports, scheduled posts; images as URLs. No session ids, tokens, keys, others' votes. **3/hour** (plus 5/min). | **Keep.** Swap SSH/devices for subscriber link and sessions; add penalties and appeals received. |
| Account deletion | Erases top-level posts and all replies under them, own replies (others' replies survive as `[deleted]`), devices, sent messages and reactions, uploaded images. Others' messages stay with them. Not undoable. Rejoin = new account. Does not delete GitHub account. | **Keep.** Does not cancel the magazine subscription (say so). Penalties survive (see rules). |
| Last seen | Only the **day** (not time) of last activity; never shown; in export; deleted with account. DAU kept as anonymous counts. | **Keep.** |
| Analytics | Fathom, anonymous: `guest-visit` once per session, `signup` after account creation. | **Adapt** to whatever the magazine uses, but keep it anonymous and minimal. |

## 11. API, CLI and integrations

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Terminal posting | `curl -fsSL https://dev-null.sh/commit \| bash -s -- 'Hello'`; signed with Ed25519/RSA SSH key on the GitHub account; reply with `--reply`, up to 3 `--image`; same moderation and 10/min; text always 3–4,000. | **Drop.** Not our audience. |
| GitHub Action | Posts from workflows (e.g. on release) with SSH key secret. | **Drop.** |
| Public read API | `/api/v1` read-only, no auth: user, user commits (`?replies=1`), single commit, followers/following. Cursor pagination, 20 posts / 30 people. Approved public only. **60/min/IP**. | **Later.** Useful for embedding stop feeds on the magazine site. |
| Signed API / devices | App holds an SSH key, signs every request; approved via `/mobile/authorize` (**10/min**); Settings → Devices lists/revokes. **120/min/user**. JSON only (no images, polls). Pinning, DMs, scheduling, search, translations web-only. | **Adapt / later.** If a mobile app comes, use Sanctum tokens; keep the "devices list + revoke" page and same-rules principle. |
| Atom feeds | `/@{username}/feed.atom`, latest **50** approved top-level posts with image enclosures, cached 5 min. | **Keep** (cheap). Also per-stop and per-line feeds. |
| Bug/feature commands | `/bug` or `/feature` turns draft into a public GitHub issue with environment details; **5/hour**; secrets must be removed. | **Adapt.** `/feedback` sends a private ticket to staff (not public). |
| Sitemaps | `sitemap.xml`, paginated users and commits sitemaps, cached 1h. | **Keep** (if public reading is allowed). |
| Upstream repo setting | `GITHUB_REPOSITORY` drives issues, changelog, insights. | **Drop.** |

## 12. Appearance, PWA and shortcuts

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Theme | Light / Dark / System. | **Keep.** |
| Navigation | Expanded or collapsed on wide screens. | **Keep.** |
| Lane dimming | Other posts fade while hovering one (default on). | **Drop** (optional polish). |
| Replies shown | 1/3/5/10/15/20/25, default 3. | **Keep.** |
| Who to follow toggle | Show/hide. | **Keep.** |
| Storage | Appearance choices are per-browser, not on the account. | **Keep.** |
| PWA | Installable manifest, own window, follows theme in title/status bars; shortcuts to Following, Latest, Notifications; focuses existing window. Service worker caches built assets only; offline page that auto-reloads; pages never cached (nothing persists after sign-out); no offline posting; no push. | **Keep.** Shortcuts: Following, Rush Hour, Near me. |
| Shortcuts | `?` help; `j`/`k` next/prev; Enter open; `y` copy link; `r`/`q`/`o` reply/quote/toggle replies; `c` compose; ⌘K or `/` search; ⌘Enter submit; ⌘⇧P preview; `g` then `l`/`e`/`p`/`s` log/trending/profile/settings. | **Keep a subset:** `?`, `j`/`k`, Enter, `c`, `/` and ⌘K, ⌘Enter, `r`. Rest optional. |
| Swipe | Between logs on touch. | **Keep** (low priority). |

## 13. Admin and ops

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Admins | `ADMINS` env list. | **Adapt.** Roles in DB: admin, moderator, staff. |
| Moderation queue | `/moderation`: pending posts; approve/reject; settings update (`PUT /moderation`). | **Keep.** Add queue filters by line, type (crime/safety first), and auto-screen score. |
| Reports queue | `/moderation/reports`; dismiss report. | **Keep.** |
| Moderated people | `/moderation/people`: timeouts and bans; lift ban. | **Keep**, plus penalty-ladder history per user and appeals queue. |
| Operations | `/operations`: overview. | **Keep.** |
| Feature flags | `/operations/features` toggle (e.g. translations kill switch). | **Keep** (Laravel Pennant). |
| Site activity / traffic / metrics | `/operations/activity`, `/traffic`, `/metrics` (product metrics). | **Keep** a simple metrics page; feeds the transparency report. |
| Failed jobs | List, retry one, delete one, delete all. | **Keep** (or rely on Horizon). |
| Local login | In local env, sign in with any username, no GitHub. | **Keep** (dev-only login as any user). |
| Throttles | sign-in 10/min; reports 5/min; stars 60/min; pins 20/min; follows 30/min; people search/previews 120/min; search 15/min; DM sends 30/min; DM other 300/min; exports 3/hour; translations 10/min; issues 10/min and 5/hour; mobile auth 10/min; API 60/min/IP unsigned, 120/min/user signed. | **Keep** as starting values. |

## 14. Pages

| Feature | How /dev/null does it | Stoop mapping |
| --- | --- | --- |
| Home | Guests → Trending; signed-in → last log. | **Keep** (Rush Hour for guests). |
| Onboarding | Review mirrored profile (Sync from GitHub), choose color, follow suggestions, learn composer/shortcuts/terminal. Skippable; never shown again. | **Adapt:** set display name and avatar, pick NATIVE or TRANSPLANT 'YY, choose home stop and favorite lines, accept community rules, follow suggestions. Skippable except rules. |
| About | Livestream project and contributors. | **Adapt:** about Stoop and the magazine. |
| Changelog | PRs merged to `production` from GitHub, a few days at a time. | **Drop** (or a simple staff-written "What's new"). |
| Brand | `/brand`: logo, wordmark, colors, type, wallpapers; right-click logo copies it. | **Drop** (magazine owns brand assets). |
| Terms / Privacy | Public; note public posts may be indexed and processed by AI. | **Keep** (magazine legal writes them). |
| Community rules | Inline in composer. | **Add** a standalone `/rules` page. |
| Transparency | n/a | **Add** `/transparency` (monthly reports). |
| Banned | `/banned` explains why; only sign out or delete. | **Keep.** Add appeal link. |
| Stop pages | n/a | **Add** `/stops/{code}`: dispatches tagged there. |
| Line pages | n/a | **Add** `/lines/{color}`. |
| Settings | Profile, Appearance, Terminal, Safety, Devices; export; delete. | **Keep** Profile, Appearance, Safety, Notifications; drop Terminal and Devices. |
| Scheduled | `/scheduled-commits`, private. | **Keep** if scheduling is kept. |
| Photo viewer | `/@{user}/commits/{id}/photos/{n}`. | **Keep.** |
| Post page | `/@{user}/commits/{id}` with ancestors, replies, continuation. | **Keep** as `/@{user}/dispatches/{id}`. |
