# Imagina Player — 1.24.0

Download **imagina-player-1.24.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  69794219a5b482d5bf6d41032c6e462adf16d908ffc95272a98a7cb8068ee7d2

## The 502 was this site's own web server

Two things pointed at it. Larger files work; and the failing one said only
"the server answered 502" — while every refusal this plugin makes carries a
reason with it. **A 502 with no reason is not ours.** It is the web server
answering on PHP's behalf.

The route that fetches a file from another domain pulled the whole thing down
in one request: to a temporary file, then read back and served. Two full
transfers of a fifty megabyte recording inside a single PHP request, with no
time limit raised. Where a host allows thirty seconds, that does not finish:
PHP is killed and nginx or Apache answers with its own 502.

### Which explains the part that looked arbitrary

The files that work are the ones on the **same domain**. The browser fetches
those directly, no PHP request is involved, and their size does not matter —
which is why a bigger file can work while a smaller one fails.

The files that fail are the ones on the **bucket**, where every byte goes
through PHP. It was never about how big the file is. It is about which of the
two paths it takes, and only then about size.

## Slices

The route serves byte ranges now, and the browser asks for four megabytes at a
time. No single request can outlast an execution limit.

The size cap applies to what was asked for rather than to the whole file, so a
recording too big to fetch in one go is still perfectly measurable a few
megabytes at a time. And the route asks for more time where the host allows it,
which costs nothing.

**Only from this site.** A `Range` header makes a cross-origin request
non-simple and the browser asks permission first — a media host that happily
serves a plain `GET` to another domain will very often refuse that. Asking
would have broken the files that work today in order to help the ones that do
not. Same-origin has no preflight, and same-origin is exactly where this
matters.

## A note on popen

Enabling it does nothing for any of this, and it is worth putting back as it
was. It is used only to run ffmpeg, and the notice mentioning it belongs to
that card.

## How it is checked

The browser test now runs against a server that honours byte ranges — PHP's
built-in one does not — and checks that a file stitched back together from a
dozen slices measures identically to the same file fetched in one go.

The failure mode of reassembly is an offset wrong by one, which still decodes
and quietly draws something else. Putting that offset back fails the test.

1156 checks green.
