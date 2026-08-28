# Imagina Player — 1.16.0

Download **imagina-player-1.16.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  934f9f435e162077257c9ff1811f2c63c949b487173419bf4d2ece3fe41224ae

## What changed in 1.16.0

**The seek bar on a video could not be dragged.** It was drawn correctly and
looked exactly like a seek bar, but the element a pointer has to land on was
zero pixels tall — everything inside the scrubber is positioned absolutely and
the video rule set the box to auto height, so it collapsed. There is now a real
hit area, a line that thickens under the pointer, and a test that presses every
control of every skin at the point where it appears.

**Video skins of their own.** Theater (controls over the picture, fading while
it plays), Minimal (a line and little else), Stacked (a solid bar under the
picture that never covers it). Until now a video block was offered the seven
audio skins, every one of which arranges a waveform and a row of transport
buttons — so choosing one either did nothing or did something meaningless. A
block now offers only the skins that apply to what it is playing, and a skin
saved for the other medium falls back instead of rendering wrongly.

**A roadmap.** `docs/VIDEO-ROADMAP.md` in the repository lists what the video
player still lacks next to Presto Player and Fluent Player — read from their
source, setting by setting — and the order it is being done in. This release is
step one of five; styling, the full per-control list, focus mode and a
watermark are the rest.

929 checks green.
