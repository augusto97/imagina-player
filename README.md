# Imagina Player — 1.13.0

Download **imagina-player-1.13.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  a9b9f06573eedded35ddde81a937d19e10e73be47d10b8108af60c0944d28a84

## What changed in 1.13.0

**YouTube and Vimeo.** Pasting a YouTube address into the Video block used to
produce an audio player that showed nothing, played nothing and had no
thumbnail: WordPress reports no file type for a web page, so the track was not
a video and the renderer wrapped an `<audio>` element around a link to
youtube.com. Provider support had never been written.

Now such an address is recognised, laid out as video with the provider's own
still image, and driven through the provider's API — so the picture carries
this player's controls, colours and calls to action rather than theirs. A call
to action at 60%, chapters, the keyboard and the scrub bar all work on a
YouTube video.

Nothing is requested from YouTube or Vimeo until somebody presses play. The
page holds their still image until then, so a video nobody watches costs a
visitor no third-party request and no cookie. YouTube loads from its no-cookie
domain by default; there is a setting for it under Video.

The block now says what it made of the address before you save, including when
it cannot play it at all — which used to be silent.

A video hosted by them is not a file on your site, so the download protection
does not apply to it, and their subtitles are drawn inside their frame.

796 checks green.
