# Imagina Player — 1.42.0

Download **imagina-player-1.42.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  6755e478bdfcaf0a0c72511a8170e07b03a9500513ca7b6455e2dd433202a43c

## What this release is

Asked: make the plugin work with Elementor, with a widget or several.

**Three Elementor widgets.** With Elementor active, the widget panel gets an
**Imagina Player** category holding the audio player, the video player and
the playlist. They are rendered by the same code as the blocks, so a player
on an Elementor page is exactly the player on any other page, and the front
end loads the same small bundle.

Each widget's panel:

- **Audio** and **Video**: the file from the media library, an address (a
  YouTube or Vimeo link, an MP4, an HLS stream) or a custom field of the
  post the page shows — the same dynamic source as the block, for product
  templates. Title, artist, cover or poster. Preset, skin, accent colour,
  corner radius. Every control as a three-way choice: the preset's answer,
  show, or hide. Calls to action: a card, a bar or an email gate at a point
  in the playback. Video adds the aspect ratio, autoplay and muting, the
  controls' hiding delay, poster fit, subtitle size, subtitle tracks,
  chapters, and the switch that hides YouTube's and Vimeo's own interface.
- **Playlist**: a list of tracks by file or address, with title and artist,
  a heading, list or grid, and a preset.

Text and address fields accept Elementor's dynamic tags, so a title or an
address can come from a post field, an ACF field or any other tag.

Nothing is registered unless Elementor is active. Elementor 3.5 or newer is
required, which is where its current widget registration arrived.

## Verified

On a real Elementor 4.4 on WordPress 6.8: the three widgets register in
their category with their panels; a page built with all three renders all
three players, with the front-end script and stylesheet enqueued; and a
widget's switch set to off reaches the rendered player. Every choice each
widget offers is checked, one by one, against what the renderer receives,
with Elementor's base classes stood in for so the check runs anywhere.

One thing I could not exercise from here is Elementor's editor itself,
whose interface needs its built assets. The widgets are rendered on request
by Elementor and the plugin's bundle picks new markup up as it arrives, so
they should appear live in the editor's preview; if a widget does not show
there, tell me what you see.
