=== Imagina Player ===
Contributors: imagina
Tags: audio, waveform, player, podcast, music
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.14.0
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

**Video, including YouTube and Vimeo.** Paste the address of a video and it plays
in this player, with your own controls, your own colours and your own calls to
action rather than the provider's chrome. Nothing is requested from them until a
visitor presses play, so a video nobody watches costs the page nothing.

= Blocks and shortcodes =

* Block: **Imagina Audio Player**
* Shortcode: `[imagina_player src="https://example.com/track.mp3" artist="…" title="…"]`

== Frequently Asked Questions ==

= Can I use a YouTube or Vimeo video? =

Yes. Paste the address into the Video block. It plays inside this player with your
own controls and your own calls to action, and the provider's still image is used
as the poster. Nothing is loaded from YouTube or Vimeo until a visitor presses
play — until then the page holds a picture, so there is no third-party request and
no cookie for a visitor who never watches.

A video hosted by them is not a file on your site, so the download protection does
not apply to it, and their own subtitles are drawn inside their frame rather than
by this player.

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

Yes. There is a Video block in the inserter and a Video section in the settings,
with a poster, fullscreen, subtitles in VTT or SRT, chapters, HLS, and the same
download protection the audio player has.

== Changelog ==

= 1.14.0 =
* Fixed: autoplay, start muted and loop did nothing on a YouTube or Vimeo
  video. The renderer prints them as attributes on an `<audio>` or `<video>`
  element, and a provider video has neither — so all three were switches in the
  block wired to nothing at all. They are now passed to the provider when the
  frame is built, which is the only moment they can be set. Looping a single
  YouTube video also needs it handed a playlist of that one video, without
  which `loop` is quietly ignored.
* New: the Video panel in the block now has real settings — the play button
  over the picture, the fullscreen and picture-in-picture buttons, the speed
  control, how the poster fills its box, how long before the controls fade, and
  whether the browser download is blocked. These existed, but only site-wide:
  per-block overrides run off the preset, and the video settings are not in a
  preset, so there was no path from a block to them. Two videos in one post
  could not behave differently from each other.
* Changed: each of those is three-way — "use site setting", show, hide — rather
  than a switch, so a block left alone keeps following the site rather than
  freezing today's setting into itself.
* Changed: audio controls no longer appear on a video block. "Show thumbnail"
  did nothing, because a video's still is the poster and has its own field; the
  waveform and played-portion colours had nothing to draw; and "stick to the
  bottom while playing" did something worse than nothing — it pinned the whole
  picture across the foot of the window, which is an audio mini-player.
* New: turning on autoplay without muting now says so in the block. No browser
  starts a video with sound by itself, and a help line under a switch is not
  the same as being told this block is in exactly that state.
* Changed: preload is no longer offered for a provider video, where nothing is
  fetched until somebody presses play.

= 1.13.2 =
* Changed: the editor no longer prints anything into the block itself except a
  fault. The line saying a video came from YouTube sat inside the block, above
  the preview, where everything reads as the post about to be published — so it
  looked like content the visitor would see, and it was telling the author
  something they already knew. It now lives in the sidebar with the block's
  other settings. An address the player cannot play still says so in the block,
  because that will publish broken and the author has to see it.

= 1.13.1 =
* Fixed: on a site whose theme styles its own buttons — which is most of them —
  the play button over a video was painted in the theme's colour. It covers the
  whole picture on purpose so it can be clicked anywhere, and during playback
  only its circle and icon fade out, so what was left was a flat sheet of the
  theme's colour over the video. Round buttons became rounded squares, icons
  took the theme's text colour, and the download control took its link colour.
* New: the player now defends the properties a theme has any business setting
  on a button, an input, an anchor or a frame of its own. The site's typeface
  still flows in, as it should; nothing else does.
* New: the whole player is rendered twice on every test run — once inside a
  stylesheet built out of what themes really ship, once without it — and every
  element of the two is compared. Until now every browser test rendered into an
  empty page, which is why none of this was caught.
* Fixed: the block preview loaded the front-end stylesheet with no version, so
  after an update the browser went on serving whatever it had cached. On a site
  updated from an older release the editor drew a video with a stylesheet that
  predated video: the picture had no shape, the poster sat at its own size and
  the controls fell out underneath it.
* Changed: the "this is a YouTube video" notice in the editor is one quiet line
  instead of a banner three lines deep above every block.

= 1.13.0 =
* New: YouTube and Vimeo. Pasting a YouTube address into the Video block used to
  produce an audio player that showed nothing, played nothing and had no
  thumbnail — WordPress reports no file type for a web page, so the track was
  not a video and the renderer built a row of audio controls around an `<audio>`
  element pointed at youtube.com. Provider videos are now recognised, laid out
  as video, and driven through the provider's own API so the picture carries
  this player's controls rather than theirs.
* New: calls to action, chapters, the keyboard and the scrub bar all work on a
  provider video, because the player asks the provider where playback is instead
  of assuming there is an element to read it off.
* New: nothing is requested from YouTube or Vimeo until somebody presses play.
  The page holds their still image until then, so a video nobody watches costs a
  visitor no third-party request and no cookie. YouTube is loaded from its
  no-cookie domain by default, which is a new setting under Video.
* New: the block says what it made of the address you gave it, before you save —
  including when it cannot play it at all, which used to be silent.
* Fixed: pasting a video address into the *audio* block made it look for a
  waveform inside a web page.
* Changed: subtitles are no longer offered for a provider video. They are drawn
  inside the provider's frame and a file added here would never have appeared.

= 1.12.0 =
* Fixed: a call to action beside an audio player rendered as a full-width sheet
  of brand colour lying across the waveform and the title, with a button in
  almost exactly the colour of the sheet behind it. The layers were inside a
  wrapper positioned to cover the picture, which audio does not have.
* Changed: beside audio a call to action is now a strip attached under the
  controls — the offer on the left, the button on the right, on a dark surface
  with a hairline of the accent down its edge. Over a video it stays a sheet
  over the picture, with the copy held to a readable column.
* Fixed: button labels were always white. On a bright accent — this plugin's
  own cyan included — white on it reads at 2.2:1, which is unreadable. The
  foreground is now worked out from the accent's luminance.
* New: the layer layout is measured in a real browser on every test run: that
  it sits beside the player rather than on it, stays inside its width, is a
  strip rather than a slab, and that the button can be told apart from what is
  behind it.
* Fixed: two contrast checks in the test suite read colours with a regular
  expression, which reads `color(srgb 0 0.7 0.78)` as near-black and so passed
  anything painted with `color-mix()`. They now resolve colours properly.

= 1.11.0 =
* New: a Track details section. Where a title and an artist come from when you
  leave the block's fields empty — the file's own tags, the name it has in your
  library, or the file name itself. Some of this already happened; none of it
  could be changed or seen.
* New: the file name is used as a last resort. "2024-03-11_mi-conferencia.mp3"
  becomes "Mi conferencia" — the leading date is filing, not a title. It is the
  only thing a track pasted from a streaming provider has, since there are no
  tags to read.
* New: cover art embedded in an audio file is used as the thumbnail.
* Changed: the block's Title and Artist fields now show what the file would
  give them, in grey, instead of sitting empty next to a player that has a
  title. An empty box gave no reason to believe anything would fill it.

= 1.10.0 =
* New: tracks hosted somewhere else can have a waveform. Until now they could
  not, by any route: ffmpeg reads local files, the generate and store endpoints
  were keyed on a media library item, and the editor's notice ignored anything
  that was not one — so a track pasted from a streaming provider got a plain
  bar and no explanation.
* New: when a host does not allow this site to read its files — which is most
  of them — the measuring goes through this site instead. That doorway needs
  the right to add media, refuses anything but http and https, refuses private
  and internal addresses, caps the size, and only ever answers with media.

= 1.9.2 =
* Fixed: after generating a waveform in the editor, the preview kept showing
  the old plain bar — so the button looked as though it had done nothing. The
  waveform is stored against the file rather than the block, so nothing in the
  block changed to make the preview go and look again. It does now.
* New: the playlist block checks all of its tracks and offers to measure the
  ones that need it, in one go. That is the case that most needed it: several
  files arrive at once, and nobody wants to press a button somewhere else once
  per file.
* Changed: the notice asks the server directly rather than waiting for a
  preview, so it appears as soon as a file is chosen.

= 1.9.1 =
* Fixed: the block editor drew a waveform for tracks that did not have one. It
  was synthetic — a stand-in so the preview would not look like a flat bar —
  and the effect was that the editor told you your waveform worked while your
  site showed a plain bar. The preview now shows what the site will show, and
  says when a waveform is missing.
* Fixed: on a host without ffmpeg, a recording longer than the visitor size
  limit could never get a waveform at all, and nothing said why. You can now
  measure it in your own browser — from the block, or in bulk under Waveforms —
  which downloads the file once, there, and stores the result for every
  visitor. Nobody browsing your site downloads anything extra.

= 1.9.0 =
* New: a video block. The player has handled video since 1.6.0, but the only
  block was called "Imagina Audio Player" and said "upload an audio file", so
  anyone looking for video in the inserter found nothing — and reasonably
  concluded there was nothing to find. There is now an **Imagina Video Player**
  block, with its own icon and its own words.
* New: a Video section in the settings. Shape, poster behaviour, how long the
  controls stay up, which buttons appear, how subtitles look, and whether the
  browser's download button is taken away. All of it was hardcoded before.
* Fixed: the video panels in the block appeared only when the file name looked
  like a video. The video block is video whatever the file is called.
* Fixed: a block that sets no shape now follows the site setting rather than
  assuming widescreen.

= 1.8.0 =
* New: calls to action. Three kinds — a panel that stops playback, a bar that
  does not, and an email gate. They work on audio as well as video: a gate two
  thirds of the way through a podcast is the same feature.
* New: captured addresses are kept, listed under Emails, and downloadable as a
  CSV. Cells that a spreadsheet would run as a formula are neutralised on the
  way out.
* New: playlists, as a list or a grid of covers. Every track is a link to its
  own file, so clicking one plays it even before any script has run; the
  runtime catches the click and hands it to the player already on the page, so
  the volume and speed the listener chose survive.
* Each of these is downloaded only by pages that use it. A page with a plain
  player is unchanged, to the byte.

= 1.7.0 =
* New: subtitles. WebVTT and SubRip, several languages, a menu to switch
  between them, and the choice remembered across videos. SubRip files are
  converted for the browser automatically — browsers read WebVTT and nothing
  else, and telling people to go and find a converter is not a feature.
* New: chapters. Marks on the progress bar and a menu to jump between sections.
  Times can be written as 90, 1:30 or 0:01:30.
* New: HLS adaptive streaming, with a quality menu built from the stream
  itself. The streaming library is downloaded only for pages that have a
  stream, and only where the browser cannot play one on its own — Safari and
  iOS play it natively and pay nothing.
* New: on a protected stream, every segment is signed, not just the playlist.
  Signing only the playlist protects nothing: the segment addresses are listed
  inside it in plain text.
* Fixed: a stream (.m3u8) was rendered as an audio player, because WordPress
  reports no file type for a playlist.

= 1.6.0 =
* New: video. A player built around the picture rather than beside it —
  poster, play button in the middle, controls over the video that fade while
  it plays, full screen, picture-in-picture, keyboard shortcuts and touch
  gestures.
* New: the video chrome is downloaded only by pages that have a video on them.
  A page with nothing but audio players is unchanged, to the byte.
* New: video files are served with the browser's own download button and
  remote playback turned off, and the right-click menu taken away. This makes
  the file harder to take, not impossible — nothing short of DRM does that,
  and the protection that matters is still the vault and the expiring link.
* Fixed: the bundle would refuse to start if an optimisation plugin inlined it
  into the page.

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
