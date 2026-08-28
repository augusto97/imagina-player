# Imagina Player — 1.14.0

Download **imagina-player-1.14.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  70c4f868d510bbebe32e81e55e58d8c072521d592b44f162f9cce543fff66c23

## What changed in 1.14.0

**Autoplay, start muted and loop now work on YouTube and Vimeo.** They were
printed as attributes on an `<audio>` or `<video>` element, and a provider
video has neither, so all three were switches wired to nothing. They are passed
to the provider when the frame is built.

**The Video panel has real settings.** The play button over the picture, the
fullscreen and picture-in-picture buttons, the speed control, how the poster
fills its box, how long before the controls fade, and whether the browser
download is blocked. These existed only site-wide before — per-block overrides
run off the preset, and the video settings are not in a preset, so there was no
path from a block to them at all. Each is three-way, so a block left alone
keeps following the site rather than freezing today's setting into itself.

**Audio controls are out of the video block.** "Show thumbnail" did nothing —
a video's still is the poster and has its own field. The waveform colours had
nothing to draw. "Stick to the bottom while playing" did something worse than
nothing: it pins the whole picture across the foot of the window, which is an
audio mini-player.

**Autoplay without muting says so.** No browser starts a video with sound by
itself, and a help line under a switch is not the same as being told this block
is in exactly that state.

872 checks green.
