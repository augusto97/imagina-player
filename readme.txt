=== Imagina Player ===
Contributors: imagina
Tags: audio, waveform, player, podcast, music
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.30.0
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

= 1.30.0 =
* Changed: a bar is now the loudness of its stretch of audio, not the loudest
  instant in it. On anything long the old measure saturates: four hundred bars
  across a fifty-three minute lesson is eight seconds of audio per bar, and the
  loudest instant in eight seconds of anybody talking is a syllable at full
  volume — every bar, all the way across. The numbers were right and the
  picture was a comb, close enough to decoration that it was reasonable to ask
  whether anything had been measured at all. Measured against a fixture that
  talks for a twentieth of one minute and the whole of the next, the old
  measure told those apart by 0.02 and this one by a factor of four.
* Changed: the same measure now in all three places a waveform is produced —
  the editor, the visitor's browser and the server's ffmpeg — so the same file
  draws the same picture wherever it was measured. The editor also scales its
  result to fill the height, which the other two always did and it never had.
* Changed: a waveform measured the old way is still drawn, and the editor now
  offers to measure it again, so nothing has to be deleted by hand first.
* Testing: a new test writes audio whose loudness is known second by second,
  measures it in a real browser with the shipped code, and checks every bar
  against what was written underneath it — including that the silent minutes
  read as zero and the blocks come back in the order they were recorded. Both
  changes were verified by putting the old measure back and watching it fail.

= 1.29.0 =
* Fixed: one dropped request threw away a download that was 99% finished. A
  fifty megabyte recording comes down in thirteen pieces, and there was no
  retry anywhere in the chain — so the thirteenth piece failing discarded the
  twelve that had arrived perfectly, and reported it as though the file could
  not be fetched at all. Pieces are now asked for up to three times. A refusal
  is not retried, because a refusal is an answer.
* Fixed: and if a piece never comes, everything but the last scrap of a file is
  still a waveform. Where at least 95% arrived, the waveform is drawn from what
  there is and the timeline is scaled so the track's length stays right. Below
  that it is still reported as a failure, because a confident picture of
  two-thirds of a recording is worse than saying so.
* Fixed: the reason a request failed was being read into a variable and thrown
  away. "This site could not reach the file's own server" covers a name that
  would not resolve, a certificate that would not verify, a timeout, and a
  connection reset at byte forty million — four different problems with four
  different fixes, and the HTTP client names which one every time. It now says
  which, in the client's own words, beside which piece it happened on.
* Fixed: the file check only ever asked for the first kilobyte, so it came back
  entirely green about a file whose measurement was failing on the last piece.
  The first kilobyte is the one request that is always cheap, always cached and
  always allowed. It now asks for the tail as well.
* Testing: the reported failure is reproduced end to end — a piece that fails
  twice and then works, a piece that never comes, and a piece missing from the
  middle, which must still refuse. Each of the three was checked by turning the
  fix back off.

= 1.28.0 =
* Fixed: a failure with a perfectly good explanation on it was reported as
  "the browser could not read it, which is usually a cross-origin refusal".
  That sentence is the last line of the code that turns a failure into words,
  reached when nothing else matched — and three separate failures reached it,
  each one arriving with a status, a step and a reason attached that were all
  thrown away on the way out. It is a convincing wrong answer, too: cross-origin
  refusals are real and common, so it sends people to look at the one place the
  problem is not.
* Fixed: a refused piece of a large file now says which piece and why. Large
  files come through in slices, and only the first slice ever read the reason
  the server gave; the rest reported the bare status, or nothing.
* Fixed: two failures had no words at all — this site being unable to open a
  temporary file to download into (a full disk, or an uploads folder it cannot
  write to), and a refusal whose reason was stripped before it got back. Both
  used to arrive as the cross-origin sentence.
* Changed: measuring a large file through this site now makes one request per
  slice instead of two. The extra request asked the media host what the file
  was before fetching a piece of it, which it can answer from the piece itself.
* Testing: the words are no longer written inside a React component where
  nothing could reach them. They are their own module, and the suite now reads
  every failure tag out of the two files that produce them — the browser-side
  measuring code and the server-side doorway — and asks the module what it would
  say about each one. A new `throw` with no words for it fails the suite. Which
  is the check that was missing all three times.

= 1.27.0 =
* Fixed: a successful ranged fetch was treated as a failure. A server answering
  a `Range` request correctly answers 206, and the check for whether the fetch
  had worked demanded exactly 200 — written before there were ranges and left
  alone when they were added. So from the moment large files started coming
  through in slices, every one of them was refused, and the site owner was told
  their media host had refused them with a 206. Which is a success code.
* Testing: the route is now exercised with a range on it, which is what would
  have caught this — and with the two requests it makes answering differently,
  which is the only way to reach the second of its two checks at all.

= 1.26.0 =
* Fixed: a file behind hotlink protection could not be measured. The site's own
  server fetched it without saying who was asking — no `Referer` at all — so a
  media host that allows the site's domain refused it, while the same file
  played perfectly in the browser, which does send one. The fetch now
  identifies the site it is made for and the plugin making it.
* Fixed: refusals were being thrown away in transit. A web server in front of
  PHP may treat a 5xx from its backend as the backend having failed and replace
  the whole response — reason header and body alike — with its own error page.
  LiteSpeed does. So every message that said exactly what had gone wrong
  arrived as a bare 502. Refusals are sent as 424 now, which no gateway
  rewrites.
* New: the file check runs the same request twice, once anonymously and once
  saying which site is asking, and reports both. That pair is what tells
  hotlink protection apart from every other reason for a refusal — and it
  reports what it identified itself as, so the address can be allow-listed.

= 1.25.0 =
* New: Settings → Imagina Player → Waveforms has a check that asks the server
  what happens when it goes for a file, and reports what it finds: the status
  the file's own host gives this server, whether that host will serve part of a
  file, how long each step took, and what PHP is actually permitted to do —
  including the live value of `disable_functions` and which SAPI is running,
  which is what settles an ffmpeg notice that will not go away. The report is
  plain text, selectable in one go, meant to be sent to whoever is helping.
* Changed: a gateway error no longer claims to know its cause. Every refusal
  this plugin makes says which step gave up, so an error carrying no reason did
  not come from this plugin — something between the browser and WordPress
  answered instead, and which of those it is cannot be told from the browser.
  The message says that and points at the check, rather than naming a setting
  it cannot see.

= 1.24.0 =
* Fixed: measuring a file hosted on another domain failed with a bare 502 once
  the file was big enough. The whole file came through this site in a single
  request — fetched to a temporary file and then read back and served, two full
  transfers inside one PHP request with no time limit raised — so where a host
  allows thirty seconds a large file never finished, PHP was killed, and the
  web server answered with its own 502. That 502 carries none of this plugin's
  reasons, which is why it said so little. A smaller file finished in time,
  which is why it looked arbitrary.
* Changed: the file now comes through in slices. The route serves byte ranges
  and the browser asks for a few megabytes at a time, so no single request can
  outlast an execution limit. It also asks for more time where the host allows
  it, which costs nothing and helps on a slow remote server.
* Changed: a file too large to fetch whole is no longer refused outright — the
  size limit now applies to what was actually asked for, so a very long
  recording can be measured a few megabytes at a time.
* Fixed: slices are only requested from this site. A `Range` header makes a
  cross-origin request non-simple and the browser asks permission first, which
  many media hosts refuse — asking would have broken the files that work in
  order to help the ones that do not.
* Fixed: a bare gateway error now says what it means, rather than repeating the
  status.
* Testing: the browser test now runs against a server that honours byte ranges
  and checks that a file stitched back together from a dozen slices measures
  identically to the same file fetched in one go — the failure mode of
  reassembly is an offset wrong by one, which still decodes and draws something
  else.

= 1.23.0 =
* Fixed: the route that measures a file hosted on another domain had never
  worked. It builds a URL by hand — what it produces has to be something an
  audio decoder can be pointed at — and that URL carries a REST nonce taken
  from `window.wpApiSettings`. The editor script never declared the dependency
  that puts that object on the page, so where nothing else happened to enqueue
  it the nonce was empty and WordPress refused the request. Nothing failed
  loudly; the file simply never got a waveform.
* Fixed: when that route did answer, it answered with a bare status. The file's
  own server refusing this site looked exactly like this site refusing the
  request, and those have completely different answers. It now says which
  happened, and passes on the status the remote server gave — a 403 from a
  bucket or a CDN is the whole answer, and it points at that service's hotlink
  protection rather than at anything here.
* Fixed: a file the browser is not allowed to read was reported as "too large
  to analyse in the browser", which sends somebody to the size settings for a
  permissions problem. The reasons are told apart now, and each has its own
  message.
* Fixed: both block previews built their runtime with an empty REST root, so
  the player inside them asked for `/peaks` against the site root and collected
  a 404 on every editor load — and the fallback that request was meant to be
  could never work.
* Fixed: a preview asks for no download at all, and that came out as "this file
  is too large" in the console for every file, whatever its size.
* Changed: the refusal reason travels in the body as well as a header, for
  sites where something in front of WordPress strips headers it does not
  recognise.
* Testing: the new checks run the route rather than reading it — a remote 403,
  an address that is not media, and an address on this machine — and confirm
  each comes back named.

= 1.22.0 =
* Fixed: long recordings never got a waveform. The editor's measurement handed
  the whole file to the browser's decoder at once, and a decoder expands a file
  at its own sample rate before resampling — so an hour of 44.1 kHz stereo is
  about a gigabyte of samples in flight, whatever rate was asked for. Files are
  now decoded a few megabytes at a time, each piece reduced and thrown away
  before the next is read, so how long a recording is stops mattering. A
  fifty-three minute file measures in a couple of seconds.
* Fixed: a failure said only "some files could not be measured here", which is
  not something anybody can act on. It now says which of the things that can go
  wrong actually did — the server refused the file, the download was cut short,
  the browser could not decode it, or it was a cross-origin refusal.
* Fixed: the download held the file twice at the moment it was largest, by
  collecting it in pieces and then copying them into one buffer.
* Testing: a new test builds a real fifty-three minute file, measures it in a
  real browser, and checks the shape of the result rather than only its size —
  and that no single decode covers more than a couple of minutes, which is the
  property the change is actually about. The same audio measured both ways has
  to give the same picture, so the pieces cannot quietly drift from the whole.

= 1.21.0 =
* Fixed: the action bar was invisible over a video. The overlay slot sat at
  `z-index: 6` while the control bar sat at 8, and the slot is its own stacking
  context — so nothing inside it could climb past the controls. A bar pinned to
  the bottom of the picture, which is the edge the controls occupy, was drawn
  underneath them: the headline came out behind the play button and the action
  button on top of the volume slider.
* Changed: the action bar now sits below the picture rather than on it, which
  is where Presto's and Fluent's do, and cannot collide with the controls at
  all. A call to action and an email gate still cover the picture, and now
  cover the controls with it, which is the point of something that stops
  playback.
* Fixed: nothing could appear before the visitor pressed play. The script only
  listened for `timeupdate`, which fires during playback — so "a bar that is
  simply there", which is what an action bar is in both of those players, could
  not be expressed. A layer set to the start is now up from the moment the page
  loads.
* Changed: adding an action bar sets it to appear at the start. Every new layer
  defaulted to 100%, so a bar added and left alone appeared only once the video
  had finished.
* New: a layer can be given an end as well as a beginning, so an offer can be a
  moment rather than a permanent fixture — Presto's overlays "appear at a
  specific time and disappear at another" and Fluent's say how long they stay,
  and this had no way to express either. Rewinding past it brings it back.
* Fixed: dismissing a call to action never stuck. The "already seen" note was
  filed under the player's DOM id, which is minted fresh on every render, so
  nothing was ever recognised on the next visit and the browser's storage
  filled with keys that could not match anything again.
* Fixed: the call to action's button was not visible as a button. Measured, its
  fill separated from the panel behind it at 1.43:1 with the factory accent —
  the label read perfectly and the shape did not. It has an edge now, and the
  site's accent is untouched.
* Fixed: the end-of-video call to action appeared and vanished in the same
  frame. "Rewind when it ends" is the default, so the player seeks back to zero
  exactly when a layer at 100% becomes due.
* Testing: a new browser test drives a player through a timeline it controls
  and asks what is on screen at each moment, checks that a click on the control
  row reaches the controls, and measures the button. It found a tenth problem
  while being written: the end time was in the schema, sanitised, rendered and
  read by the script, and did nothing, because the payload the page receives is
  rebuilt key by key and nobody had added it there.

= 1.20.0 =
* Fixed: the close button on a call to action was nearly invisible. The
  stylesheet's own defence against themes strips every button's background, and
  this one's had been restated for its hover state and not for the state it
  spends its life in — so on any real site it was a white glyph with nothing
  behind it, over whatever the video happened to be showing.
* Fixed: a theme's `p { margin: 1.5em 0; line-height: 2; font-size: 18px }` —
  one of the most ordinary things a theme says — reached the call to action's
  copy and nearly doubled the panel's height beside audio.
* Fixed: the email gate's field ignored a theme's padding in its width and
  ended up 43 pixels outside the player.
* Fixed: the spam honeypot sat at `left: -9999px`, which inside page content is
  an element ten thousand pixels to the left of the article. It is clipped in
  place now.
* Changed: the block's settings are reorganised. There were ten panels for a
  video, two of them called "Colours", with subtitle sizes filed under "Video"
  and a "Video" panel holding a corner radius, a poster, thirteen dropdowns and
  a second set of colours. There are now eight, each named for the question it
  answers — Media, Appearance, Controls, Playback, Subtitles, Chapters and
  previews, Calls to action, Advanced — with every setting in exactly one of
  them and only the first open.
* Changed: a setting that can inherit is a segmented control rather than a
  dropdown. Thirteen three-way dropdowns in a column cost a click each to read;
  the segments show all three answers at once, say what the site's own setting
  is, and mark the rows this block has changed.
* Changed: which controls a player shows and how it behaves are separate
  questions and were in the same list. Sticking to the corner, remembering the
  position and stopping when the video leaves the screen are in Playback;
  subtitles-on-from-the-start is with the subtitles.
* New: the preset preview can be looked at as a video. A preset's accent paints
  the play button over a picture, its corner radius rounds it, its button
  colour is on the bar — and the preview only ever drew audio.
* New: the Video settings have a live preview of their own, showing the
  settings as they stand on screen rather than as they were last saved.
* Testing: the hostile-theme test walks every element inside the player and
  compares it with and without a theme, which sounded exhaustive and was not —
  no case it rendered had a call to action on it, so the panel, its button, its
  form and its close button were never in the tree being walked. They are now,
  and they are what found all four fixes above.

= 1.19.0 =
* Fixed: rounding a video's corners drew a frame around it. The rounded shell
  is the audio player's card — asking for a radius on a row of controls means
  asking for the card they sit in, so it carries padding and a faint tint. On a
  video that padding became a pale ring and the tint became a border nobody
  asked for. A video already rounds the right thing: the picture itself.
* New: the colours of the playback controls. The buttons and the clock on the
  bar, and the played part of the seek bar with the volume knob beside it —
  site-wide and per block. Both were fixed in the stylesheet: the icons were
  white whatever colour the bar behind them was set to, so a pale control bar
  hid its own buttons; and the played line took the waveform's progress colour,
  an audio setting the video block does not show, so the one moving coloured
  thing on the picture could not be reached at all.
* New: left on Automatic, the buttons are worked out from the control bar the
  same way the accent's foreground is, so an existing site gets readable
  controls without touching anything.
* New: the audio player's small buttons — mute, skip, speed, download, and the
  volume rail — have a colour, on the preset and on the block. That was a fixed
  slate grey, which all but disappears on a dark page.
* Fixed: the play button in the control row printed a white icon on the accent
  without checking. The big play button over a video already worked that out;
  this one assumed.
* Fixed: the time chips kept a black pill on a pale control bar, so a correctly
  darkened clock was printed on black anyway.
* Testing: two browser tests. One measures the rendered boxes — is the picture
  flush with the shell, is anything painted behind it, is the curve there — and
  checks the audio card keeps the padding and tint the rule exists for. The
  other measures real contrast on the control bar for a dark bar, a pale bar and
  a hand-picked one, compositing each translucent colour over what is behind it
  first: the rail is thirty per cent of a colour, and reading it without its
  backdrop reported 1.29:1 for a pair that is plainly legible.

= 1.18.0 =
* New: the plugin speaks Spanish. 471 strings extracted into
  `languages/imagina-player.pot`, a complete `es_ES` translation, and the
  compiled `.mo` and per-bundle `.json` files, all shipped in the archive.
* Fixed: the plugin never called `load_plugin_textdomain`. WordPress opens a
  plugin's own `.mo` files only when it is told where they are, so a complete
  translation would have shipped and never been read — and nothing errors when
  that happens, `__()` simply hands back the English.
* Fixed: the release archive had no `languages/` folder at all, so even a
  correctly loaded text domain would have found nothing on an installed site.
* Fixed: every bundle was handed the whole catalogue, and the front-end bundle
  — which has no `__()` call in it, since its handful of strings come from PHP
  — was told to fetch one on every page view. Each catalogue now carries only
  the strings its own sources use, and the front end has no file to fetch.
* Fixed: the caption search's three strings were missing from the payload PHP
  sends the player, so that box would have stayed in English on a Spanish site.
* New: the corner radius can be set per block, not only on the preset.
* New: caption search — a box that finds the moment a word is said and jumps to
  it, reading the subtitles the video already carries. Accents and case fold,
  so *pagina* finds *página*, while `ñ` is left alone.
* Testing: `tests/test-translations.php` checks the template against the source,
  the translation against the template, the placeholders inside every translated
  string, the compiled catalogue byte for byte, which strings each bundle
  downloads, and then renders a real player with the catalogue loaded and reads
  the Spanish back out of the markup. The harness's translation stubs used to
  return their input unchanged, which meant no test could tell a loaded
  catalogue from an unloaded one; they now consult one when a test sets it.

= 1.17.0 =
* Fixed: chapters were never delivered on a real site. Their track is a `data:`
  URI, and WordPress's `esc_url()` only allows the protocols in
  `wp_allowed_protocols()` — which does not include `data` — so the attribute
  came out empty. No test noticed because the harness's own `esc_url()` was
  `htmlspecialchars` alone, which also let `javascript:` through. Both are
  fixed, and the honest one found the chapter bug within a minute.
* New: the control bar's colour and the subtitles' colour, size and backing,
  per block and site-wide. All four were hard-coded, so a player could carry a
  site's colours everywhere except the two places somebody looks at while a
  video is playing.
* New: every video control has its own answer — the play button over the
  picture, the title, the times, skip, volume, speed, subtitles, chapters,
  picture-in-picture, fullscreen. Half of these lived on the audio preset,
  which is why a video block showed a mixture of two lists and neither was
  complete. For a video the video settings are now the authority.
* New: stop when nobody is watching. Pauses when the tab goes to the background
  or the picture scrolls off the screen. It does not start again by itself.
* New: subtitles on from the first frame, for an audience that mostly watches
  with the sound off. A viewer who turns them off is remembered and this does
  not override that.
* New: a mark over the picture, with a corner and an opacity. It is not
  protection and is not offered as any — a screen recording keeps it and a crop
  removes it — but it makes a copy traceable.
* Fixed: an address that cannot be a mark now leaves no element at all, rather
  than an empty `<img>` every browser reports as broken.

= 1.16.0 =
* Fixed: the seek bar on a video could not be dragged. It was drawn correctly —
  the line paints at the right place and looks like a seek bar — but the element
  a pointer has to land on was zero pixels tall, because everything inside the
  scrubber is positioned absolutely and the video rule set the box to auto
  height. The video could not be scrubbed at all, and it looked like it should
  be. There is now a real hit area, a line that thickens under the pointer, and
  a test that presses every control of every skin at the point where it appears.
* New: video skins of their own — Theater (controls over the picture, fading
  out while it plays), Minimal (a line and little else), and Stacked (a solid
  bar under the picture that never covers it). Until now a video block was
  offered the seven audio skins, every one of which arranges a waveform and a
  row of transport buttons, so choosing one either did nothing or did something
  meaningless.
* Changed: a block offers only the skins that apply to what it is playing, and
  a skin saved for the other medium falls back instead of rendering wrongly —
  which is what happened when an audio file was replaced with a video.
* New: `docs/VIDEO-ROADMAP.md`, which lists what the video player still lacks
  next to Presto Player and Fluent Player, read from their source, and the
  order it is being done in.

= 1.15.0 =
* New: a video that follows the reader. Scroll away from one that is playing
  and it detaches into a small card in a corner, keeping its shape, with the
  controls on it and a button to send it away — which stays away. The switch
  for this existed before but did the audio version of it: a full-width bar
  across the foot of the window, which for a video is a whole picture lying
  across the bottom of the screen.
* Fixed: a player already off screen when playback started was never
  reconsidered, because an observer reports changes and nothing had changed.
  An autoplaying video below the fold, or a playlist carrying on to the next
  track, would play to nobody.
* New: stills on the seek bar. Point at the bar and the moment under the
  pointer appears above it. Give the block a WebVTT storyboard — what most
  video tools export — and nothing is downloaded until somebody actually drags
  the bar, so a reader who never scrubs pays nothing for it.
* New: the compressed size of the bundle and the stylesheet are now checked on
  every test run, alongside the source sizes. Those are the numbers a visitor
  pays and the ones the description claims.

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
