# Imagina Player — 1.23.0

Download **imagina-player-1.23.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  d64d464672118929cf7da6dbec974da1f5a57dbcfa446877e67fe0bd21d47a7e

## The route for a file on another domain had never worked

A browser cannot read a file from another domain unless that domain allows it,
and media hosts mostly do not. So there is a route on the site that fetches the
file and hands it over same-origin — the one path that exists for exactly this
case.

It builds its URL by hand, because what it produces has to be something an
audio decoder can be pointed at, and a hand-built REST URL needs the nonce that
`apiFetch` would otherwise add for you. That nonce comes from
`window.wpApiSettings`, which is put on the page by `wp-api-request` — which
the editor script never declared as a dependency.

Where nothing else on the screen happened to enqueue it, the request was
refused and the file simply never got a waveform. Nothing failed loudly.

## And the failure said the wrong thing, twice

**"This file is too large to analyse in the browser."** The size check treated
"I learned nothing about this file" the same as "this file is too big" — so a
file the browser is not allowed to read sent people to the size settings, where
nothing can help. The reasons are told apart now, and each has its own message.

**A bare status from the route.** The file's own server refusing this site
looked exactly like this site refusing the request, and those have completely
different answers. It now says which happened and passes on the status the
remote server gave: a 403 from a bucket or a CDN is the whole answer, and it
points at that service's hotlink protection or signed-link rules rather than at
anything in this plugin.

The reason travels in the response body as well as a header, for sites where
something in front of WordPress strips headers it does not recognise.

## A 404 on every editor load

Both block previews built their runtime with an empty REST root, so the player
inside them asked for `/peaks` against the site root every time the editor
opened. Harmless — a stored waveform reaches a preview in the markup — but it
is a failed request in everyone's console, and the fallback it was meant to be
could never work.

A preview also asks for no download at all, and that came out as "this file is
too large" for every file, whatever its size.

## How it is checked

The new tests run the route rather than reading it: a remote 403, an address
that is not a media file, and an address on this machine, each in its own
process because the handler ends by streaming and exiting. Each has to come
back naming what happened.

1144 checks green.
