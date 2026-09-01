# Imagina Player — 1.22.0

Download **imagina-player-1.22.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  9c16c4caa723483a20387433465e7cb102b42a2c70a8e500e6d32cd6cd943674

## Long recordings could not be measured in the browser

On a host with no ffmpeg the waveform is measured once, in the editor's own
browser, and stored for everybody. It worked for a podcast episode and not for
a lecture.

The measurement handed the whole file to the browser's decoder and asked for an
8 kHz context, on the reasoning that the context's rate is what comes back.
That is true of the result and not of the work: a decoder expands the file at
its *own* rate and resamples afterwards. Fifty-three minutes of 44.1 kHz stereo
is about a gigabyte of float samples in flight before anything is handed back.

Whether that gigabyte is fatal depends on the machine — which is why it was
some files and not all of them, and why it could not be reproduced on the
machine this was fixed on. So the change is not "the old way crashes" but "the
new way never asks for it": the file is decoded a few megabytes at a time, each
piece reduced to a handful of numbers and thrown away before the next is read.
How long a recording is stops mattering.

A fifty-three minute file now measures in a couple of seconds.

WAV windows are given a header of their own, since a WAV has no frames to
resynchronise to. Everything else can be cut on a byte boundary, because MP3,
AAC and Ogg all carry a sync word at the head of every frame and a decoder
handed a slice starting mid-frame simply skips to the next one.

The pieces are laid back on a timeline by their decoded length rather than
shared out evenly, so a variable bitrate file does not come back stretched.

## A failure that said nothing

"Some files could not be measured here. They may be too long for this browser,
or served from somewhere it cannot read them."

That covers four different problems with four different answers and names none
of them. It now says which one happened: the server refused the file and with
what status, the download was cut short, the browser could not decode it, or it
was a cross-origin refusal.

The same is true of the bulk generator on the settings screen, which counted
its failures and threw away every reason.

## How it is checked

A new test builds a real fifty-three minute file, measures it in a real
browser, and checks the shape of the result rather than only its size — a row
of identical bars is the same picture as no waveform at all, and would pass
every other check.

It also checks the property the change is actually about: no single decode
covers more than a couple of minutes of audio, whatever the length of the file.
Putting the old path back fails that immediately.

And the same audio measured both ways — whole, and in pieces — has to give the
same picture, across six windows and their seams, or the fast path is quietly
drawing something else.

1117 checks green.
