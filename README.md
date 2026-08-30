# Imagina Player — 1.21.0

Download **imagina-player-1.21.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  fd14a5351e4aedd14c16b2d0dda2222e1fdcc01a8c0ae8eb98d3a0b2713d5dff

## The calls to action barely worked, for five reasons at once

The action bar was being painted, in the right place, underneath the control
bar.

**The stacking.** The overlay slot sits at `z-index: 6` and the control bar at
8, and the slot is its own stacking context — so nothing inside it could climb
past. A bar pinned to the bottom of the picture is pinned to the same edge the
controls are on: the headline came out behind the play button and the action
button on top of the volume slider. A call to action had the control bar drawn
across its foot.

**Nothing could appear before play.** The script listened only for
`timeupdate`, which fires during playback. So a layer could not be on screen
until somebody pressed play, and a bar that is simply *there* could not be
expressed at all.

**The default was the end.** Every new layer appeared at 100%. Add an action
bar, leave the slider alone, and it shows once the video has finished.

**Dismissing never stuck.** The "already seen" note was filed under the
player's DOM id, which is minted fresh on every render — so nothing was ever
recognised on a later visit, and the browser's storage filled with keys that
could not match anything again.

**The button was not visible as a button.** Measured, its fill separated from
the panel behind it at 1.43:1 with the factory accent. The label read
perfectly; the shape did not. It has an edge now, and the site's accent is
untouched.

## What Presto and Fluent had that this did not

**An end as well as a beginning.** Presto's overlays "appear at a specific time
and disappear at another"; Fluent's say how long they stay. This had no way to
express either, so every layer was permanent once shown. A layer can now be
given a window, and rewinding past it brings it back.

**An action bar below the player.** Presto puts its action bar underneath the
video and Fluent's "sits below the player" — which is the collision above,
solved by construction rather than by stacking. Ours sits there now. A call to
action and an email gate still cover the picture, and now cover the controls
with it, which is the point of something that stops playback.

## And one more, found while writing the test

The end time was in the schema, sanitised, rendered into the markup and read by
the script — and did nothing, because the payload the page receives is rebuilt
key by key and nobody had added it there. Nothing errored; the layer simply
never went away.

There is a guard for that now: every field the script decides with has to be in
what the server sends, and nothing the server sends may go unread.

## How it is checked

A new test drives a real player through a timeline it controls and asks what is
on screen at each moment — before play, at a third, at the halfway mark, after
a window closes, at the gate, and after the video rewinds itself. It checks
that a click on the control row reaches the controls, that a call to action
covers them, and it measures the button.

Two of its own checks were wrong first and were fixed before being trusted: one
hit-tested a control bar that had faded out and taken its pointer-events with
it, so it passed with the bug put back; the other read a border colour without
checking the border had any width.

1107 checks green.
