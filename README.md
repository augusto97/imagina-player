# Imagina Player — 1.12.0

Download **imagina-player-1.12.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  c180f95b9e17e3fa95426a1b4ed8953e605bffc205d1222db394137102d52584

## What changed in 1.12.0

A call to action beside an audio player rendered as a full-width sheet of brand
colour lying across the waveform and the title, with a button in almost exactly
the colour of the sheet behind it. Three faults behind that:

* The layers sat inside a wrapper positioned to cover a video's picture. Audio
  has no picture, so it covered the player. On audio the wrapper is now in the
  flow, and the panel sits under the controls.
* The panel was 92% of the accent and the button 100% of it — 1.18:1. The panel
  is now a dark surface with a hairline of the accent down its edge, laid out as
  a strip: the offer on the left, the button on the right. Over a video it stays
  a sheet over the picture, with the copy held to a readable column.
* Button labels were always white. On a bright accent white reads at 2.2:1, so
  the label colour is now worked out from the accent's luminance.

The layout is measured in a real browser on every test run — that the panel sits
beside the player rather than on it, stays inside its width, is a strip rather
than a slab, and that the button can be told apart from its background.

739 checks green.
