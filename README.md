# Imagina Player — 1.40.2

Download **imagina-player-1.40.2.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  c6f14492f3c32360cd45e4021af5e0ccd3eb6aa0c797f12fb30a0f535e7a4b6d

## What this release is

Reported: the editor shows the player with the browser's bare controls and
no styling, and the console shows the plugin's own files refused —
`style-frontend.css`, `frontend.js`, `preview-frame.css` — each with
`ERR_BLOCKED_BY_RESPONSE.NotSameOrigin 403`.

The preview runs in a sandboxed frame, on purpose. A sandboxed frame has no
origin of its own, so to your server every request it makes comes from
nowhere: `Origin: null`, a cross-site fetch with no referrer. Your host
refuses exactly that for static files — hotlink protection, a
`Cross-Origin-Resource-Policy` header on `wp-content`, or a firewall rule
does it — so the stylesheet and script never reached the preview. The front
end was never affected: there the files are requested by the page itself.

**The preview now brings its own files.** The editor page, which is the
site, fetches the stylesheet and the script and writes them into the frame
as text. The frame then has nothing left to request, and no rule on static
files can touch it. The settings screen's live preview had the same fault
and the same fix. Where a file cannot be fetched even by the page, it is
linked as before.

Nothing needs changing on your host.

This replaces 1.40.1, which went out with one test red; the fix is the same
and firmer: a file that never answers is given up after eight seconds and
linked instead, and a missing address is skipped rather than failed on.

## Verified

Against a server that refuses the plugin's files to cross-site requests, in
a real Chromium: the previous frame stays unstyled on it and its script
never runs; the new one is styled and runs. In the real block editor on such
a server, the video stage takes its 16:9 height again.
