=== Imagina Player ===
Contributors: imagina
Tags: audio, waveform, player, podcast, music
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, accessible waveform audio player for WordPress, built for the block editor.

== Description ==

Imagina Player renders an audio player with a real waveform, a Gutenberg block, and
reusable presets so a whole site can be restyled from one screen.

It is deliberately small. The front-end bundle is about 5 KB gzipped with no
runtime dependencies — no jQuery, no player framework — and a page only loads it
when it actually contains a player.

**Waveforms without the wait.** Peaks are computed once and cached: on the server
with ffmpeg when the host provides it, otherwise by the first visitor's browser,
whose result is posted back so nobody else repeats the work. Peaks are stored as
one byte per bar, so a 400-bar waveform costs about half a kilobyte.

**Presets.** A preset bundles skin, colours and which controls are visible. Blocks
reference a preset and may override single values, so changing the house style is
one edit rather than one edit per post.

**Accessible and resilient.** The seek bar is a real ARIA slider with keyboard
support, every control is a real button, and the player is server-rendered around
a native `<audio controls>` element — if the script fails to load, the audio still
plays.

= Blocks and shortcodes =

* Block: **Imagina Audio Player**
* Shortcode: `[imagina_player src="https://example.com/track.mp3" artist="…" title="…"]`

== Frequently Asked Questions ==

= Does it need ffmpeg? =

No. ffmpeg makes waveforms appear instantly on first view; without it the first
visitor's browser computes the waveform once and the site caches it.

= Can I use audio hosted somewhere else? =

Yes. Any HTTPS URL works, including files served from a streaming provider or CDN.
Waveforms for external URLs are cached in the plugin's own table, keyed by URL.

= Does it support video? =

The renderer and the front-end core already handle `<video>` sources, but the
video-specific UI (fullscreen, captions, poster, chapters) is not built yet.

== Changelog ==

= 0.1.0 =
* First release: waveform audio player, Gutenberg block, presets, shortcode,
  server and browser waveform generation, REST peaks cache.
