# Imagina Player — 1.39.1

Download **imagina-player-1.39.1.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  d8e9dcc0beddc5b85e5f2f02661f2c15ab3638f32e53b6701bc2c68198fb93dd

## What this release is

Reported: "in the WordPress editor the videos are not taking the right
height — on the front end they do, in the editor they don't."

The block preview kept its starting height, 150 pixels, whatever it held.
It runs in a sandboxed frame, on purpose, so that nothing the renderer
prints can reach the editor — and the same wall kept the editor from
measuring it. The measuring code read a document it could not reach,
measured nothing, and never set a height. An audio player happens to fit in
150 pixels, which is why this went unnoticed from the first version; a
16:9 video shows its top fifth. The settings screen's live preview had the
same code.

**The frame now reports its own height** to the editor: on load, once the
canvas has painted, and again whenever its content changes size — a poster
loading, a waveform arriving, the window resizing. The editor accepts a
report only from the frame it created, and only a sane number. It listens
on the window that owns the frame, because on a block theme the block lives
inside the editor's canvas frame and the report arrives there, one window
down from where the editor's code runs.

Nothing changes on the front end, which was already right.

## Verified

In the real block editor on WordPress 6.8 with a block theme: a 16:9 video
block is shown whole at the width of the canvas. A Chromium test holds a
real sandboxed frame, proves its document is unreadable from outside,
and checks that its report arrives, follows a change in size, is heard from
inside a canvas frame, and is ignored when it comes from any other frame.
