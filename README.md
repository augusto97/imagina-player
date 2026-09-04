# Imagina Player — 1.39.0

Download **imagina-player-1.39.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  c335bd75b1530d4ee117b2fbb90016ce329350c7813b85f7f9e3ebaafef02df6

## What this release is

Reported: "the video's picture only works with YouTube — a Vimeo video does
not bring one."

On a real WordPress with Vimeo answering, the same lookup produced the
picture: the request is the one WordPress itself makes for a pasted Vimeo
link, and the rendered player carried the poster. What was missing was any
account of what Vimeo had said, and a memory that kept a single failed attempt
for an hour. A private video, a host that cannot reach Vimeo, and a plugin not
trying all looked exactly the same: a black rectangle.

**The editor now says why.** Beside the poster field, in your language:
"Vimeo answered 403: the video is private, or its owner has restricted where
it may be embedded", or whatever the HTTP client on your server reported,
word for word. Under it, **Ask Vimeo again**, which forgets the remembered
answer and asks once more — for an author who has just made the video public.

**A failure to reach Vimeo is remembered for five minutes, not an hour.** A
timeout during one preview, or Vimeo being down, used to cost the next hour.
A refusal from Vimeo keeps the hour, with the button above for the case where
it no longer applies. A miss remembered by an earlier version is asked again
rather than trusted, so a site stuck on one before this release un-sticks
itself on the next preview.

**The block preview no longer needs an administrator.** It asked for the
right to manage options, so an author or editor whose role cannot got "The
preview could not be loaded" on every block. It asks for the right to edit
posts now.

## What to do with the Vimeo video that brought no picture

Open its block in the editor and read the notice. If it names a 403, the
video is private or restricted to certain domains on Vimeo's side, and no
setting here can change that: choose a poster in the field below it. If it
says the site could not reach Vimeo, the words after the dash are what your
server's HTTP client said, and that is what to hand to your host.

## Verified

In the real block editor on WordPress 6.8: a Vimeo block on a host that
cannot reach Vimeo shows the notice with the client's own words beside the
poster field, and a mocked Vimeo answer produces the picture in the rendered
player. The lookup is covered by tests driven with the answers Vimeo actually
gives — a picture, a 403, a 404, a timeout, an outage, an answer with no
picture, and a picture on a host that is not Vimeo's.
