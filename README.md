# Imagina Player — 1.38.0

Download **imagina-player-1.38.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  b874af9144368e3230601c3e3019370ad2e446ca091fdf2948f755207cde1be6

## What this release is

The 1.37.0 audit was checked against the code. This one was checked against
WordPress: the plugin was installed on a real WordPress 6.8 (on the SQLite
adapter, since the environment has no MySQL), and every claim the audit made
was exercised there — activation, the markup the block editor saves, the front
end in a real Chromium, the waveform round trip, the protected vault through
core's own media endpoint and through the signed stream, the upgrade path,
the lead limits over HTTP, and uninstall.

Most of it held. Two things did not, and both are fixed with a test that
fails on the previous code.

**A settings request that named one setting switched off the rest of its
group.** "Not mentioned" and "off" were the same thing to the endpoint. A
request carrying only the ffmpeg path switched off server generation and the
browser fallback, and the front end stopped measuring waveforms; one carrying
only the YouTube interface switch turned off privacy mode and every video
control. The settings screen sends whole groups, so it never showed there —
anything else talking to the endpoint saw it. A request now changes what it
names and nothing else.

**Uninstall left the vault directory behind.** Every protected file was moved
back, and the folder stayed, empty but for its deny rules. It is removed once
nothing but those rules is left in it. A folder that still holds a file keeps
its rules beside it, because removing them would expose whatever is there.

## What was verified and left alone

* A page without a player loads none of the plugin's assets, on a block theme
  and on a classic one. A page with one loads the script and the stylesheet.
* The browser fallback measures a track the site has no waveform for, stores
  it, and the next page view is served from the site instead of measuring
  again. The stored row carries the current format version.
* The media endpoint no longer names the vault directory to anyone, signed in
  or not. The original address answers 404 once a file is protected.
* The vault's signed stream answers Range requests with 206 and the right
  headers, and refuses a tampered or missing token with 403.
* The sixth lead from one address in a day is refused with 429; an address
  that is not an address is refused with 400.
* A site updated by uploading the plugin, with the waveform table missing its
  format column, gets the column back on its next request.
* Uninstall leaves no option, table, transient or moved file behind.

## Still true

If you enabled `popen` on your host during the waveform trouble, put it back
the way it was. It was never the cause and it is not needed.
