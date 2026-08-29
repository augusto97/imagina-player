# Imagina Player — 1.17.0

Download **imagina-player-1.17.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  df87b5b53a6fe7b22bc82dd08f9f834436efa0c2dfc9055505531c8c435186bf

## What changed in 1.17.0

This finishes the video roadmap — steps two to five.

**Colours and subtitles.** The control bar's colour and the subtitles' colour,
size and backing, per block and site-wide. All four were hard-coded, so a
player carried a site's colours everywhere except the two places somebody looks
at while a video plays.

**Every control has its own answer** — the play button over the picture, the
title, the times, skip, volume, speed, subtitles, chapters, picture-in-picture,
fullscreen. Half of these lived on the audio preset, which is why a video block
showed a mixture of two lists and neither was complete.

**Stop when nobody is watching.** Pauses when the tab goes to the background or
the picture scrolls off. It does not start again by itself.

**Subtitles from the first frame**, without overriding a viewer who has already
chosen for themselves.

**A mark over the picture**, with a corner and an opacity. Not protection, and
not offered as any: a screen recording keeps it and a crop removes it. It makes
a copy traceable.

## Two bugs the test harness was hiding

The harness's own esc_url() was htmlspecialchars alone. Real WordPress empties
a URL whose scheme is not allowed; the stub passed javascript: straight
through, so every test that leaned on it for safety was testing nothing.

Making it honest found this within a minute: **chapters have never been
delivered on a real site.** Their track is a data: URI, data is not an allowed
protocol, so WordPress emptied the attribute — silently, on every install,
while the suite stayed green. Fixed.

962 checks green.
