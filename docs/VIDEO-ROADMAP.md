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

- [ ] Corner radius for the picture, per block
- [ ] Control-bar colour and its foreground
- [ ] Caption size, colour and background, reachable rather than hard-coded
- [ ] A logo/watermark over the picture, with a corner and an opacity

### 3. Controls — a toggle each, as both competitors have

Presto toggles thirteen controls individually: large play, small play, rewind,
fast forward, progress, elapsed time, mute, volume, speed, picture-in-picture,
fullscreen, captions, auto-hide. Fluent's list is the same shape.

This plugin has some of these on the audio preset and some on the video
settings, which is why the block shows a mix of both and neither list is
complete.

- [x] **done** — the video settings a block may answer for itself (1.14.0)
- [ ] One coherent list per medium, with every control in it
- [ ] Rewind and fast-forward offered on video, not only on audio

### 4. Behaviour

Presto: `save_player_position`, `captions_enabled` (captions on by default),
`play_video_viewport` (its "Focus Mode" — play only while the tab is visible
and the video is on screen), `on_video_end`, `sticky_scroll`.

- [x] **done** — sticky on scroll, with a corner and a dismiss (1.15.0)
- [x] **done** — remember playback position
- [x] **done** — what happens at the end
- [ ] Captions on by default
- [ ] Focus mode: pause when the tab is hidden or the video scrolls away

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
2. Video styling: radius, control-bar colour, captions.
3. The full per-control list for video.
4. Focus mode and captions-on-by-default.
5. Watermark over the picture.

Each step ends with the players rendered and looked at, not only asserted.
