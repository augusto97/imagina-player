# The video player: what it is missing, and in what order

This file exists because the video half of this plugin was built feature by
feature in response to complaints, and that produces a list of features rather
than a player. The two plugins used as the reference — Presto Player 4.4.1 and
Fluent Player 1.4.0, both read from source — were gone through setting by
setting, and what follows is the difference between them and this.

It is kept up to date as work lands. A line marked **done** has a test that
fails when it is broken; anything else is not started, whatever the code looks
like.

## How the comparison was made

Presto's authority is `inc/Models/Preset.php` — the video preset schema, which
is the complete list of what one of its players can be told. Fluent's is
`app/Services/PresetService.php`, whose default preset carries the same kind of
list under `controls`, `behaviors` and `styles`. Both were read in full rather
than sampled, and every row below names where it came from.

## The state of things

### 1. Skins — **the worst gap**

Presto ships three video skins (`default`, `modern`, `stacked`) and Fluent two
(`classic`, `modern`). This plugin has seven skins and **all seven are audio
skins**: they arrange a waveform, a row of transport buttons and a title. A
video block offers them anyway, so choosing one either does nothing visible or
does something meaningless.

A video skin is a different thing: it decides where the control bar sits, how
the buttons are grouped, whether the title is over the picture, and how the bar
behaves when the pointer leaves.

- [x] **done (1.16.0)** — three video skins of their own: `theater` (controls
      over the picture, fading), `minimal` (a line and little else), `stacked`
      (a solid bar under the picture that never covers it). The stacked one is
      a difference in where the markup goes, not only in paint: the stage crops
      to the video's shape, so a bar inside it can only be over the picture.
- [x] **done (1.16.0)** — the block offers only its medium's skins, and a skin
      saved for the other medium falls back rather than rendering something
      meaningless. An author swapping an audio file for a video kept "card with
      cover" on a picture that has no cover.
- [ ] Each skin looked at on a phone as well as a desktop

### 2. Styling — nothing video-specific is settable

Presto: `border_radius`, `caption_style`, `caption_background`, `hide_logo`.
Fluent: caption `font_size`, `background`, `color`; control-bar colours.

This plugin offers an accent colour and — until this week — a waveform colour
on a video, which has no waveform. There is no way to set the corner radius of
the picture, the colour of the control bar, or how captions look, from either
the block or the settings screen.

- [x] **done (1.17.0)** — the control bar's colour, per block and site-wide.
      It was hard-coded near-black, so a player carried a site's colours
      everywhere except the strip somebody looks at while the video plays. The
      alpha that lets the picture through it is kept whatever colour is chosen.
- [x] **done (1.17.0)** — subtitle colour, size (four now, not three) and what
      sits behind them, per block and site-wide.
- [x] **done (1.17.0)** — a mark over the picture, with a corner and an
      opacity. Not sold as protection: a screen recording keeps it and a crop
      removes it. It makes a copy traceable, which is the honest reason.
- [ ] Corner radius per block (it exists on the preset, not on the block)

### 3. Controls — a toggle each, as both competitors have

Presto toggles thirteen controls individually: large play, small play, rewind,
fast forward, progress, elapsed time, mute, volume, speed, picture-in-picture,
fullscreen, captions, auto-hide. Fluent's list is the same shape.

This plugin has some of these on the audio preset and some on the video
settings, which is why the block shows a mix of both and neither list is
complete.

- [x] **done** — the video settings a block may answer for itself (1.14.0)
- [x] **done (1.17.0)** — one list per medium with every control in it: the
      play button over the picture, the title, times, skip, volume, speed,
      subtitles, chapters, picture-in-picture and fullscreen. For a video the
      video settings are now the authority; the preset's `show_*` flags describe
      an audio player, and letting both apply is what produced a mixture.

### 4. Behaviour

Presto: `save_player_position`, `captions_enabled` (captions on by default),
`play_video_viewport` (its "Focus Mode" — play only while the tab is visible
and the video is on screen), `on_video_end`, `sticky_scroll`.

- [x] **done** — sticky on scroll, with a corner and a dismiss (1.15.0)
- [x] **done** — remember playback position
- [x] **done** — what happens at the end
- [x] **done (1.17.0)** — subtitles on from the first frame, without
      overriding a viewer who has chosen for themselves.
- [x] **done (1.17.0)** — focus mode: stop when the tab goes to the background
      or the picture scrolls away. It does not resume; starting a video under
      somebody because they scrolled back is the behaviour everyone complains
      about.

### 5. Things neither of them has that this one does

Worth keeping in view so they are not lost while catching up: signed expiring
links with the file outside the public folder, waveforms for audio, a hostile
theme test, and a weight budget checked on every run.

### 6. Known faults found while writing this

- [x] **fixed** — the seek bar on a video was zero pixels tall. The bar was
      drawn, so it looked right, but nothing could be dragged: the scrubber was
      `height: auto` and everything inside it is positioned absolutely. Now
      there is a real hit area, and `test-hit-targets.php` presses every control
      in every skin at the point where it appears.

## Order of work

1. ~~Video skins, and offering only the skins that apply.~~ **Done in 1.16.0.**
2. ~~Video styling: control-bar colour, captions.~~ **Done in 1.17.0.**
3. ~~The full per-control list for video.~~ **Done in 1.17.0.**
4. ~~Focus mode and captions-on-by-default.~~ **Done in 1.17.0.**
5. ~~Watermark over the picture.~~ **Done in 1.17.0.**

Each step ended with the players rendered and looked at, not only asserted.

## What is left

The list above is finished. What remains is smaller and is written here so it
is not mistaken for nothing:

- Presto's `hide_youtube` — a way to hide YouTube's own overlay. It is
  experimental in Presto and unreliable by their own description, and it works
  by covering parts of somebody else's player with opaque boxes, which breaks
  whenever they change their layout. Not doing it.

Done since this list was written:

- Corner radius per block. `borderRadius` on all three blocks, overriding the
  preset, clamped and sanitised on the way through.
- Presto Pro's caption search. A box that reads the subtitle tracks the video
  already carries — no index, no extra request — folds accents so *pagina*
  finds *página*, and jumps to the cue.
- Translations. `languages/imagina-player.pot` with 471 strings, a complete
  `es_ES` translation, the compiled `.mo`, and a per-bundle `.json` for the
  editor and the admin screen. `tests/test-translations.php` checks the
  template against the source, the translation against the template, the
  placeholders in every translated string, the compiled catalogue byte for
  byte, and — the part that matters — renders a real player with the catalogue
  loaded and reads the Spanish back out of the markup.

## Faults found while doing this, and fixed

- The seek bar on a video was zero pixels tall (1.16.0).
- `esc_url()` in the test harness was `htmlspecialchars` alone, so it passed
  `javascript:` straight through — every test that leaned on it for safety was
  testing nothing and passing. Fixed in 1.17.0, and it immediately found the
  next one.
- Chapters were never delivered on a real site. Their track is a `data:` URI,
  and `esc_url()` only allows the protocols in `wp_allowed_protocols()`, which
  does not include `data` — so WordPress emptied the attribute. The weak stub
  is why no test noticed.
- The watermark class was `.imgp__mark`, which is already the chapter marker on
  the scrub bar. Caught before it shipped; every chapter tick would have become
  a full-size logo.
- The plugin never called `load_plugin_textdomain`, so the `.mo` files would
  have shipped and never been opened. WordPress loads a domain by itself only
  from `wp-content/languages/plugins`; a plugin carrying its own has to say
  where they are. Nothing errors when it is missing — `__()` hands back the
  English it was given — so the whole translation would have been dead weight
  (1.18.0).
- The release archive had no `languages/` folder in its list of contents, so
  even a working text domain would have found nothing to load on an installed
  site (1.18.0).
- Every bundle was handed the whole 45 KB catalogue, and the front-end bundle
  — which contains no `__()` call at all, since its few strings come from PHP —
  was told to fetch one on every page view. Now each `.json` carries only the
  strings its own sources use, and the front end has no file (1.18.0).
- The caption search's three strings were never in the runtime payload PHP
  sends the player, so the search box would have stayed in English on a Spanish
  site. This is the failure mode of a fallback: nothing breaks, it just never
  translates. `test-translations.php` now compares the keys the front-end
  sources read against the keys the server sends, in both directions (1.18.0).
- The translation stubs in the test harness returned their input unchanged, so
  nothing in the suite could tell a loaded catalogue from an unloaded one. They
  now consult a catalogue when a test sets one — which is what lets the suite
  render a player and read Spanish back out of it (1.18.0).
