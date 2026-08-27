=== Imagina Player ===
Contributors: imagina
Tags: audio, waveform, player, podcast, music
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.5.1
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

= Can I stop people downloading my audio? =

Mark a file as protected and it moves out of the public uploads folder, served
only through a signed link that expires. That stops the URL being copied, shared
or hotlinked, and it can require a login or defer to your membership plugin. It
cannot stop someone who is allowed to listen from recording what they hear —
nothing short of DRM can, and this plugin is honest about that.

= Does it support video? =

The renderer and the front-end core already handle `<video>` sources, but the
video-specific UI (fullscreen, captions, poster, chapters) is not built yet.

== Changelog ==

= 1.5.1 =
* Fixed: the JavaScript linter could not run at all — a TypeScript version its
  plugins predate had been pulled in — so nothing had ever been linted. It runs
  clean now, and `npm test` runs the linters, the type checker and the test
  suite together so it cannot rot again unnoticed.
* Fixed: each toggle on the settings screen now names the control it belongs to
  outright, instead of relying on being wrapped around it.

= 1.5.0 =
* New: a protection self-check. It writes a decoy file into the protected
  folder, fetches it over real HTTP with no login, and reports what the server
  actually answered — which is the only way to catch the common failure where
  nginx never reads the .htaccess the plugin writes and every "protected" file
  sits in the open.
* New: the logo is chosen from the media library. It still accepts a pasted
  URL, since a logo often lives outside the library.
* Fixed: "ffmpeg was not found" was shown for three different problems with
  three different fixes — a host that forbids starting processes, a path typed
  in wrong, and nothing installed. Each now says which one it is.
* Fixed: saving a new ffmpeg path and re-reading its status happen in one
  request, and the status came from before the save.

= 1.4.0 =
* Fixed: the player overflowed its container on phones. Every skin was measured
  at 320, 360, 414 and 768 pixels with all controls on; the title now shrinks
  instead of pushing, and the controls take a line of their own on narrow
  screens.
* Changed: factory colours are neutral. A fresh install no longer arrives
  wearing the colours of somebody else's player; set yours once under Branding.
* Changed: the settings screen has its own identity, separate from whatever
  colour a client gives their player.

= 1.3.1 =
* Fixed: the block preview grew scrollbars and swallowed clicks and drags in the
  editor. The preview frame no longer scrolls, and it is inert — every click and
  drag belongs to the editor, as it should for something you only look at.

= 1.3.0 =
* Fixed: the block preview drew a hand-made copy of the player that had fallen
  behind the real one — the card, compact and pill skins all appeared as the
  plain stacked layout. It now renders the actual player, the same way the
  settings screen does.

= 1.2.4 =
* Fixed: the block's colour settings still sat behind an overflow menu and could
  not be collapsed. They are now an ordinary collapsible panel with a swatch, a
  hex field and a reset for each colour.

= 1.2.3 =
* Fixed: the block's appearance settings sat behind a "+" menu that hid them
  until you knew to look, and the waveform height is now a slider with a reset.

= 1.2.2 =
* Fixed: the preset background was a bare text field with no colour picker,
  because it also accepts "transparent". It is now an explicit choice between
  transparent and a colour, with the picker alongside.

= 1.2.1 =
* Fixed: the settings screen followed the browser's dark-mode preference, which
  turned that one screen dark while the rest of wp-admin stayed light and kept
  painting dark headings onto it. It now uses a single light palette, like the
  admin around it.

= 1.2.0 =
* Fixed: the settings screen's live preview showed "no audio file selected"
  instead of a player, and its headings inherited their colour from wp-admin
  instead of declaring it.
* New: Branding — site-wide default colours that a new preset starts from, plus
  an optional logo on every player.
* New: custom CSS, loaded after the player stylesheet on pages with a player.
* New per preset: a description, a corner radius, what happens when a track
  finishes (rewind, repeat or stop), and where a sticky player docks.

= 1.1.0 =
* New: a purpose-built settings screen replacing the WordPress options form —
  preset list, a Controls / Behaviour / Style editor, and a live preview that
  renders through the real player rather than an imitation of it.
* New: four more skins — mirrored waveform, card with cover, compact one-line,
  and pill — bringing the total to seven.
* New: the block picks a cover image and a download file from the media
  library instead of asking for a URL.
* New: "Generate missing waveforms" moved into the new screen, alongside the
  ffmpeg status and the browser size limit.

= 1.0.1 =
* Fixed: a file too long to analyse in the browser left the "analysing"
  highlight sweeping across the player for ever. The browser now checks the
  file size before committing, gives up after 30 seconds, and the highlight
  stops regardless.
* Fixed: a player with no waveform drew a row of stubby bars that read as a
  broken player. It now falls back to a plain seek bar.
* Added: "Generate missing waveforms" in Settings → Imagina Player, so long
  recordings get their waveform on the server without waiting for WP-Cron.
* Added: a size limit for browser-side analysis, 25 MB by default.
* Fixed: the volume slider is now styled explicitly, so themes that restyle
  range inputs cannot turn it into an unrecognisable box.

= 1.0.0 =
* First release.
* Waveform audio player: server-rendered around a native audio element, canvas
  waveform, keyboard-accessible seek bar, sticky player, remembered position.
* Gutenberg block and `[imagina_player]` shortcode sharing one attribute schema.
* Reusable presets, editable from Settings → Imagina Player.
* Waveform peaks generated by ffmpeg out of the request path, or by the first
  visitor's browser, and cached as one byte per bar.
* Protected media: signed expiring links with HTTP range support, optional login,
  user and network binding, X-Accel-Redirect / X-Sendfile delivery, and an
  `imagina_player_can_stream` filter for membership and course plugins.
