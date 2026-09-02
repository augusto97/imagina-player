# Imagina Player — 1.37.0

Download **imagina-player-1.37.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  ffae6abeb6b296bc99cf3de1ec957aff631a6d3eeeff16a7bec072a047652315

**Install this one.** It closes a real exposure for anyone whose protected files
sit on nginx, and it fixes two things that were silently not working.

## How the audit was done

Four independent reviews ran in parallel — PHP security, front-end security,
code correctness, performance — and returned about fifty findings between them.
**Every one was checked against the code before anything was changed**, and
several were rejected as wrong or already handled. Every fix that survived was
then confirmed by putting the fault back and watching a test fail.

## What was found

### Security

* **High, on nginx and any server that ignores `.htaccess`.** The protected
  vault's unguessable directory name — the one thing standing between "needs a
  server config line" and "wide open" — was written into attachment metadata,
  and WordPress publishes that metadata verbatim on `/wp-json/wp/v2/media` to
  anyone. It is no longer written, and stripped on read for files protected by
  earlier versions.
* One anonymous request could make a track's waveform endpoint answer 500 for
  good: `"1e999"` is numeric, becomes infinity, and infinity cannot be JSON
  encoded. Clamped where every path writes through.
* The ffmpeg path setting accepted anything and was run through the shell in a
  way that left room for arguments. An administrator could run an arbitrary
  command. Absolute path, path characters only, executable file.
* The fetch-on-behalf doorway had no size limit while downloading and left its
  temporary file behind on five of its six exits.
* Leads were rate-limited per email only; a script rotating addresses filled
  the table without bound. A second limit per network.
* The waveform endpoint answered for any attachment id, confirming existence
  and length of private and protected media. It applies core's own visibility
  rule now.
* Plus nine smaller ones: admin-only tools that required only upload rights,
  forced regeneration with no throttle, an unsanitised storyboard address, a
  sibling-directory path check, an editor link to whatever was pasted,
  unsandboxed preview frames, unescaped inline JSON, undeclared parameters.

### Correctness — two of these you would have hit

* **The Video and Track-details panels never saved.** The screen posted five
  groups of settings and had seven; edits to those two were quietly written
  back over by the server's unchanged copy. Every "Saved." on those panels was
  false. And "Hide YouTube's own interface" was never persisted either.
* **"Generate missing waveforms" found nothing on any real site.** 1.35.0
  searched posts for `imagina-player/` — the REST namespace — and the blocks are
  registered under `imagina/`. Its test had been written with the same wrong
  name, so it proved the code against its own assumption. Both now come from
  the class that registers the blocks.
* A hide-controls delay of zero, documented everywhere as "never", hid them on
  the first frame. A video skin in a preset used by an audio block drew the
  wave skin with no waveform under it. Measuring a file again with the same
  result was reported as a server error. Uninstall left captured email
  addresses behind and left every protected file inside a directory that denies
  access, with nothing left to serve it.

### Performance

* The stylesheet was declared as a block style, which a classic theme loads on
  **every page of the site**, player or not. That is 30 KB of render-blocking
  CSS on pages with nothing on them.
* Three version markers, each stored without autoload, each read on every
  request: three database queries per page view, site-wide, forever.
* A player with no stored waveform spawned `ffmpeg -version` on every page
  view. A playlist of N uploads cost 2N queries. Players removed from the page
  were never released.

## Deliberately not changed

* The doorway still accepts a response with no `Content-Type`, because storage
  buckets send none and those are the files it exists for. The residual
  exposure is behind an author's login, on public-looking names and three
  ports, and is never rendered as anything but audio bytes.
* A visitor can still be the first to store a waveform for a track. It is
  cosmetic, clamped, and an editor can redo it.
* The self-check's loopback request keeps TLS verification off, for hosts with
  self-signed loopback certificates.

## What could not be verified here

This machine has no WordPress install and no route to YouTube. The `.htaccess`
behaviour, the REST metadata exposure and the classic-theme stylesheet loading
were verified by reading core's behaviour, not by running it. Your own site's
**Comprobación de protección** answers the nginx question for your server.

1371 checks green.
