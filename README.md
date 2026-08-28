# Imagina Player — 1.13.1

Download **imagina-player-1.13.1.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  5a56aae562b222d6925f316947830e25da6df3f98d1ebe02acb1fa3a9c5ea5f1

## What changed in 1.13.1

**The theme was painting the player.** The play button over a video covers the
whole picture so it can be clicked anywhere, and during playback only its
circle and icon fade out. A theme that styles its own buttons — most do — was
therefore painting the video with a flat sheet of its own colour. The same
reach turned round buttons into rounded squares, gave the transport icons the
theme's text colour and the download control its link colour, and inflated a
row of icons to the height of a "Add to cart" button.

The player now restates, for the elements it owns, the properties a theme has
any business setting on a button, an input, an anchor or a frame of its own.
Your typeface still flows into it, as it should. Nothing else does.

**The editor was showing a stale stylesheet.** The block preview loaded the
front-end CSS with no version, so after an update the browser kept serving what
it had cached. On a site updated from an older release the editor drew a video
with a stylesheet from before video existed — no shape to the picture, the
poster at its own size, the controls falling out underneath.

**And the reason neither was caught.** Every browser test rendered the player
into an empty page. There is now one that renders it twice — inside a
stylesheet built out of rules themes really ship, and without it — and compares
every element of the two. That test found four more faults than the report did.

834 checks green.
