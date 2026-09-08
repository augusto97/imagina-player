# Imagina Player — 1.42.1

Download **imagina-player-1.42.1.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  3c67956c22d105d17308ddc48e1771ce151a1f74c4ebd6c33df15c902db1b640

## What this release is

Reported: a Vimeo video showed no picture in the player, in this plugin and
in Presto Player alike, while WordPress's own Vimeo embed showed it. The
editor's notice read: "Vimeo answered, but without a picture for this
video."

That notice was exact. For a video its owner has hidden from Vimeo.com, or
allowed only on chosen sites, Vimeo answers the usual endpoint with the
player and nothing else — no title, no picture. WordPress's embed block
never asks for a picture: it puts Vimeo's whole player on the page, and the
player draws its own still from the browser. Any plugin that asks from the
server got nothing.

**The picture is now asked for at a second door.** When the first gives
none, the plugin reads the player's own configuration — the same thing
Vimeo's player reads when it loads — which lists the stills it draws, and
takes the widest. Every request to Vimeo also names your site as the asker,
which is how a video restricted to chosen sites lets the player's own
request in.

A note: that second door is not a documented part of Vimeo's API. It is what
Vimeo's own player uses and it has been stable for years; if Vimeo ever
changes it, the plugin falls back to the honest "no picture" and the
poster field, and nothing else is affected.

If the picture still does not appear after installing: open the block, and
under Media press **Ask Vimeo again**, since the earlier answer is remembered
for an hour. If the notice is still there, paste its text.

## Verified

Against recorded answers shaped like Vimeo's: an oEmbed answer without a
picture followed by a player configuration with stills yields the widest
still; an unlisted video's code travels to the second door; a still on a
host that is not Vimeo's is refused; a refusal at the first door is not
followed by a second knock; every request names the site. Vimeo itself is
not reachable from where this was built, so the client's own video was not
fetched here.
