# Imagina Player — 1.19.0

Download **imagina-player-1.19.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  75876b9a1bd0036f590b104abeff521857d601994d56af2c272b6f4a3eaa9ae0

## Rounded corners drew a frame

Turning on rounded corners for a video put a pale ring and a faint border
around the picture.

The rounded shell is the audio player's card. Asking for a radius on a row of
controls means asking for the card those controls sit in, so the rule carries
padding and a tint along with the curve — which is right, and is what it was
written for. The same class landed on a video, and there the padding became a
ring and the tint became a border.

A video already rounds the right thing: the picture itself. So the shell just
had to stop drawing a card.

## The playback controls have colours now

Two of them were literals in the stylesheet with no way to reach them.

**The buttons and the clock on the bar** were white, whatever colour the bar
behind them was set to. Pick a pale control bar and every button on it
disappeared.

**The played part of the seek bar** took the waveform's progress colour — an
audio setting the video block does not show, because a video has no waveform.
The one moving coloured thing on the picture could not be changed at all.

Both are settings now, site-wide and per block, and both default to
**Automatic**: the buttons are worked out from the bar the way the accent's
foreground already was, and the played line takes the accent. An existing site
gets readable controls without touching anything.

The audio player's small buttons — mute, skip, speed, download, and the rail
the volume slider runs along — have a colour too, on the preset and on the
block. That was a fixed slate grey, which all but disappears on a dark page.

## Three more found on the way

The volume rail on a video was drawn from the audio icon colour: a slate grey
line on a near-black bar.

The play button in the control row printed a white icon on the accent without
checking, while the big play button over the picture worked the same thing out
properly.

The time chips kept a black pill on a pale control bar, so a correctly darkened
clock was printed on black anyway.

## How it is checked

Neither report is a thing a string search settles, so both are measured in a
browser.

One test measures the rendered boxes: is the picture flush with the shell, is
anything painted behind it, is the curve actually there — and, in the other
direction, does the audio card keep the padding and the tint the rule exists
for. A fix that flattened both would be no fix.

The other measures real contrast on the control bar for a dark bar, a pale bar
and a hand-picked one. It caught a bug in itself first: it read translucent
colours as opaque, so a rail drawn at thirty per cent alpha came back as its
base colour and reported 1.29:1 for a pair that is plainly legible. Every
sample is composited over what is behind it now.

Both tests were checked by putting each bug back and watching them fail.

1062 checks green.
