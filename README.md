# Imagina Player — 1.28.0

Download **imagina-player-1.28.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  42e4beb70748526d91f7edca1f38b93d2b4d3a6c36161d84dad7d7419ae3ee50

## The message you have been shown three times was the wrong one

> el navegador no lo pudo leer, que suele ser un rechazo por origen cruzado

That sentence is the **last line** of the code that turns a failure into words.
It is what comes out when nothing else matched. It is not a diagnosis; it is a
shrug with a plausible story attached.

And it is plausible — cross-origin refusals are real, and common, and exactly
what a file on another domain does. Which is why it has cost an afternoon of
looking at CORS headers, a look at whitelists that were already correct, and a
PHP function enabled against a host's warning. None of those were the problem.
Each time, the real failure had arrived carrying a status, a step and a reason,
and all three were thrown away on the way out.

## What it will say now

Every failure the browser or this site can produce has words of its own, and a
failure part-way through a large file says **which part**:

    the server hosting the file answered 403 to this site as well — check
    that domain's hotlink protection or signed-link rules (slice 9 of 13)

Slice 9, not "the file". That distinction matters: a first slice refused is a
host that will not serve this site at all, and a ninth slice refused is a host
that started saying no once it had been asked a dozen times — a rate limit, and
a completely different fix.

Two failures had no words at all and were reported as the cross-origin line:

* this site being unable to open a temporary file to download into — a full
  disk, or an uploads folder it cannot write to;
* a refusal whose reason was stripped before it got back.

And a slice refused only ever read the reason on the **first** request. The
rest reported a bare status, or nothing.

## Why it kept happening

The mapping lived inside a React component. Nothing could run it — you could
read it and believe it was complete, which is what happened, three times.

It is its own module now, and the suite reads every failure tag out of the two
files that produce them — the browser-side measuring code and the server-side
doorway — and asks the module what it would say about each one. That is how the
two missing ones were found, rather than by waiting for somebody to hit them.

Adding a `throw` with no words for it now fails the suite. Verified by adding
one, on both sides.

## Also

Measuring a large file through this site now makes **one request per slice**
instead of two. The extra request asked the media host what the file was before
fetching a piece of it — which the piece itself already answers.

1212 checks green.

## Still worth doing on your server

`popen` was enabled during this hunt, against the host's warning, on the theory
that it was the problem. It was not, and the check confirms it is still listed
in `disable_functions` regardless. It is used for one thing here — running
`ffmpeg` — and there is no `ffmpeg` on that server to run. **Turn it back off.**
