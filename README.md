# Imagina Player — 1.33.0

Download **imagina-player-1.33.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  405b5e9bc43680220cc83ffb1536b7e299db8ca15fed009266b7ba9a1fec0008

## The author's tools are now where the author's tools are

"Measure this waveform again" was drawn **inside the block**, above the player.

In the editor a block is a picture of the page, so anything drawn there reads as
part of the page. Somebody looking over an author's shoulder cannot tell an
editing control from content, and would reasonably assume that line was going to
appear on the finished site — which meant explaining it every time.

Both the waveform notice and the source warning are now in the block's sidebar:
under **Medios** for the audio and video block, and in a **Ondas** panel for the
playlist. The block shows the player and nothing else.

## And the red panel about ffmpeg is gone

You were right about it and it was worse than you said.

It appeared **on every visit to any site without ffmpeg**, whether or not one
single file was missing a waveform. So on a site like yours — everything
measured, everything working, browser measuring doing exactly what it is
designed to do — the settings screen opened with a red alarm saying the server
cannot do a thing nobody had asked it to do. To a client, that is "your site is
broken".

It now counts what is actually missing first:

* **Nothing missing** → nothing is said. Because nothing is wrong.
* **Something missing** → a plain note in neutral colours saying how many files
  and which button to press, rather than a complaint about the server.

The technical reason (`popen` is in `disable_functions`, so ffmpeg cannot run)
moves to the help text under the **ffmpeg path** field — where somebody
wondering why that field does nothing is already looking, and where it is an
answer instead of an alarm.

What it still does is say so plainly when files genuinely have no waveform.
Going silent in that case would be the same mistake in reverse.

## How this is kept

A new check reads which components are drawn inside the block and which are in
the sidebar, and requires the author-only ones to be in the sidebar. It also
requires the ffmpeg note to depend on a count, and to be a note rather than a
warning.

Both were confirmed by putting the old behaviour back and watching the test
fail. The first attempt at the second one edited nothing — the condition had
been reformatted across three lines and the patch silently missed — and a
negative test that changes nothing proves nothing, so it was redone properly.

1318 checks green.

## Still worth doing on your server

**Turn `popen` back off.** Now that the screen no longer nags about it, there
is even less reason to leave it on.
