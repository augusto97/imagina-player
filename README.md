# Imagina Player — 1.41.0

Download **imagina-player-1.41.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  ff4d9f22ee523ea663fae53d1f5f8c62531eb513b84f935a27d5c9a62bf956f3

## What this release is

Asked: does the YouTube block control speed, subtitles and picture quality
from this player's own interface?

Speed already did. Quality nobody can: YouTube retired that control from its
API in 2019, and every player that claims it only hides YouTube's interface.
Subtitles did not — this player hid its subtitles button for YouTube and
Vimeo, and with the provider's interface hidden too, a video with subtitles
had no way to turn them on. That is what this release adds.

**The subtitles button now switches the provider's own subtitles.** Once the
video has started — that is when the provider's frame exists and can say
which languages it has — the button appears, lists **Off** and every
language, and each pick goes to YouTube or Vimeo through its API. The
subtitles are drawn by the provider, in the provider's style: their size and
backing cannot be taken from this player's settings, because the provider
does not hand the text over. The viewer's remembered language applies as it
does to the player's own tracks, and the block's "subtitles on from the
start" too — for YouTube it is also passed as YouTube's own switch, so they
show from the first frame.

**A note on YouTube.** Turning subtitles on from the start is a documented
parameter. Listing the languages and choosing one use a module YouTube's
player API has carried for years without documenting; every player that
offers YouTube subtitles from its own bar uses it. If YouTube ever removes
it, the button will simply not appear for YouTube videos — nothing else
breaks. Vimeo's calls are all documented.

## Verified

Driven in a real Chromium against stand-ins for both APIs that record what
they are told: the button hidden before play and shown after; the menu
listing Off and every language; each pick reaching the provider as the right
call; the choice remembered for the next video; and a viewer's earlier choice
applied without asking. The Vimeo stand-in includes a chapters track, which
is correctly left out of the menu.
