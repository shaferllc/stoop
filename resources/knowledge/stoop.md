# About Stoop

Stoop is a social network for DC, run by a city magazine. People post short "dispatches" about the city: food, going out, culture, real estate, questions for neighbors, and the suburbs.

## Posting
- A dispatch is up to 500 characters. You can add up to 3 photos with descriptions, a poll (2 to 4 options, running 1 hour to 7 days), or schedule it up to 30 days ahead.
- Types: Dispatch, Ask DC, Poll, Happening (an event with a time), Been here (a place you went), and Crime & safety. Staff can post Callouts.
- Every dispatch has a Metro line as its topic: Red is Food, Orange is Going Out, Blue is Culture, Green is Real Estate, Yellow is Ask DC, Silver is Maryland and Virginia. You can also tag a Metro station.
- Dispatches can't be edited. You can delete one and undo within 30 seconds.
- You can post 10 dispatches a minute at most.
- Press c to write, j and k to move between posts, and ? for all keyboard shortcuts.

## Feeds
- Following shows people you follow. Rush Hour ranks what neighbors are talking about right now. Latest is everything, newest first. Near me shows posts tagged with your stations (set them in Settings).

## Half-smokes
- Turn on half-smokes in Settings to get 10 a day to give to dispatches you love. They reset at midnight DC time. They're just for fun and don't affect rankings.

## Badges and ranks
- You earn badges like Doors Open (first dispatch), All Lines (posted on every line), Station Hopper (10 stations) and Regular (7 days in a row). XP adds up into a Metro rank, from Tourist to Mayor.

## Going live
- From your profile you can mark yourself "On the scene" with a YouTube, Twitch or Instagram link for 1 to 12 hours.

## Terminal and API
- In Settings, create an API token. Then post from a terminal: curl -fsSL https://stoop.test/dispatch | STOOP_TOKEN=yourtoken bash -s -- "Red Line is delayed at Dupont"
- Public feeds: /feeds/latest.atom, /feeds/line/red.atom, /feeds/stop/E04.atom.

## Safety and rules
- No attacks on people for who they are. Crime and safety posts can't describe someone by race or gender alone.
- No campaign posts and no networking pitches.
- Report a post from its ··· menu. It disappears for you right away; when several people report it, it's hidden until a moderator reviews it.
- Mute or block people from the same menu. Settings → Safety lists who you've muted or blocked and lets you limit replies to people you follow.
- Breaking the rules leads to a warning, then timeouts of 1 hour to 30 days, then a ban. Every removal and penalty can be appealed once, and a different moderator reviews it.
- A public transparency report at /transparency shows how moderation went each month.
