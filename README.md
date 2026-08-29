# Imagina Player — 1.20.0

Download **imagina-player-1.20.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  f18b78d6fc7174cc99c05e328cefa9c9ed33c9273ef12c9423be9d243bc1f3ec

## The close button on a call to action

It was nearly invisible, and it was broken rather than unconfigurable.

The stylesheet defends itself against themes by stripping every button's
background — a theme's own `button { background: #ff87ac }` would otherwise
paint the sheet that covers the video pink — and the handful of buttons that do
have a shape of their own restate it. This one restated its hover state and not
the state it spends its life in. On any real site it was a white glyph at
three-quarter opacity with nothing behind it, over whatever the video happened
to be showing.

## Three more the same test found

The test that should have caught it walks every element inside the player and
compares it with and without a theme. That reads as exhaustive. It was not: no
case it rendered had a call to action on it, so the panel, its button, its form
and its close button were never in the tree being walked.

Adding those cases found:

- A theme's `p { margin: 1.5em 0; line-height: 2; font-size: 18px }` — one of
  the most ordinary things a theme says — reaching the panel's copy and nearly
  doubling its height beside audio.
- The email gate's field landing 43 pixels outside the player, because a
  theme's padding was added to its width rather than taken out of it.
- The spam honeypot parked at `left: -9999px`, which inside page content is an
  element ten thousand pixels to the left of the article. It is clipped in
  place now.

## The block's settings are reorganised

Ten panels for a video, two of them called "Colours", the subtitle sizes filed
under "Video", and a "Video" panel holding a corner radius, a poster, thirteen
three-way dropdowns and a second set of colours.

Nothing was missing. It was impossible to guess where anything was, which for
somebody using it is the same problem.

Eight panels now — Media, Appearance, Controls, Playback, Subtitles, Chapters
and previews, Calls to action, Advanced — each named for the question it
answers, every setting in exactly one of them, and only the first one open.

**A setting that can inherit is now a segmented control, not a dropdown.** All
three answers are visible at once, the inherit segment says what the site is
actually set to rather than just "Site", and the rows this block has changed
are marked down the side. Thirteen dropdowns in a column cost a click each to
read; the same thirteen now fit in a third of the height and can be scanned.

**What a player shows and how it behaves were in the same list.** Sticking to
the corner, remembering the position and stopping when the video leaves the
screen are in Playback. Subtitles-on-from-the-start is with the subtitles.

## Previews for video

A preset's accent paints the play button over a picture, its corner radius
rounds the picture, its button colour sits on the bar — and the preset preview
only ever drew audio. It now switches between the two.

The Video settings had no preview at all, so every setting there was a guess
followed by publishing a post to look at it. They have one now, showing the
settings as they stand on screen rather than as they were last saved.

1087 checks green.
