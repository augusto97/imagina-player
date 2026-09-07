# Imagina Player — 1.40.3

Download **imagina-player-1.40.3.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  f692d8850d024464a41d9ac2663623eb8a6b2d6ce0c9bdf41f941d8d9d024d66

## What this release is

Reported after 1.40.2: the editor's preview loads and looks right, but the
console shows `ChunkLoadError: Loading chunk 549 failed` for
`imagina-provider.js`, asked for at `/wp-admin/imagina-provider.js` and
refused with `ERR_BLOCKED_BY_RESPONSE.NotSameOrigin 403`.

The player's script loads its video chrome, the YouTube and Vimeo shell,
the calls-to-action layers and a few other pieces on demand, from beside its
own file. Since 1.40.1 the script is written into the preview frame as text,
so "beside its own file" became the frame's own address — nowhere — and the
browser fell back to the page's, `/wp-admin/`. Your host then refused the
request the same way it refused the stylesheet before.

**The preview now brings those pieces too.** The plugin tells the player
where the pieces really live, hands the preview every one of them as a
versioned address, and the preview writes each into the frame after the
script. The pieces register themselves, so the player never has to ask for
them. The only one left out is the HLS library: half a megabyte, for a
stream a preview never plays.

**And a small thing seen along the way:** every video page view, front end
included, carried a request for a waveform and a 404 in the console for it.
A video never draws a waveform, yet the player was handed the key to ask
with. It is not any more. Audio is unchanged.

Nothing needs changing on your host.

## Verified

Against a server that refuses the plugin's files to cross-site requests, in
the real block editor: the video preview is enhanced, with its chrome and
its provider shell, and nothing is requested from inside the frame. In
Chromium, against the same kind of server: a refused piece written in this
way runs, after the script that needs it.
