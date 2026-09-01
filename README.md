# Imagina Player — 1.36.0

Download **imagina-player-1.36.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  d0bf5e5faf60f3ea80866ca848325a9318b03d64415ea28bc0bfaf346632739a

## A video on YouTube that does not look like YouTube

`controls=0` was already on: it takes off the control bar and **nothing else**.
The title, the channel avatar, the "Watch on YouTube" button and the grid of
suggested videos at the end answer to no embed parameter, and every one of them
is a way off the page the visitor is on.

The page cannot reach into the frame to style them away — that is the
same-origin policy, and there is no way round it. So each is dealt with where
it actually is. Three mechanisms, because no single one covers all three.

### 1. The frame never sees the mouse

Most of what YouTube draws appears on hover. A frame that receives no pointer
events is never hovered, so it never appears. Nothing is lost by it: every
control on screen is this player's own, and they sit above the frame.

### 2. The frame is three times the height of the box, and centred

This is the part that does the real work, and it is worth knowing why it costs
nothing.

YouTube pins its bars to the edges of **the player**, which fills the frame,
while the picture is fitted inside and centred. So make the frame three times
taller than the visible box: a picture fitted to the frame's width lands
exactly on the middle third — the part you can see — with a whole box-height of
empty player above and below it. The bars are up there, out of sight.

Measured, on a 640×360 box: the picture covers the box **to the pixel**, and
the bars clear it by 312. No cropping into the picture, nothing lost.

### 3. Playback stops a fraction before the end

The end grid is drawn over the picture rather than at the frame's edges, so no
crop can reach it, and `rel=0` has only limited it to the same channel since
2018. So the video is paused a fifth of a second short of the end and the
player raises "ended" itself. Everything listening for that — the end-of-video
call to action, the playlist moving on, the poster coming back — cannot tell
the difference.

On by default. Switchable per video in the block sidebar, and site-wide under
Ajustes → Imagina Player → Vídeo.

## One thing to know before you use it

YouTube's terms for the embedded player ask that it is not obscured. Every
commercial player plugin does this and the setting exists because people ask
for it, but it is your call rather than one taken quietly for you — so the
setting says so, right next to the switch.

## What was tested, and what was not

**This machine has no route to youtube.com.** So none of this was measured
against YouTube, and the test says so in its own header rather than leaving it
implied.

What it measures is the geometry the technique depends on, against a stand-in
built to the same shape — bars at the player's edges, picture fitted inside and
centred — in a real browser. The same page is measured twice, with the crop and
without, so the "before" is a control rather than an assumption: without it,
the bars really are over the picture.

It also demands a hundred pixels of clearance rather than merely "outside the
box", because almost any overscan satisfies the loose version — the first
attempt at this test passed with a crop that had 24 pixels to spare.

If YouTube moves its bars to sit against the picture instead of the frame, this
test will still pass and the feature will still be wrong. That is the honest
limit of it.

## Two things found while testing

* The overscan was written as numbers in **two** rules, one of them
  `!important` — so only that one ever mattered and the other was decoration. A
  negative test proved it by changing the decoration and passing. One custom
  property now, with the centring offset derived from it.
* The hostile-theme test asserted the frame was exactly 100% of the stage by
  area, standing in for "a theme cannot shrink it". It now checks the frame
  *covers* the stage — the thing that was meant, and still true when the frame
  is deliberately larger.

1357 checks green.
