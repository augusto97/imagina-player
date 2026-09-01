# Imagina Player — 1.29.0

Download **imagina-player-1.29.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  f69926252cbb7a4227cc2785300708f5e8c40e70f056ebacf7bcd5c1df3db052

## Twelve slices of thirteen arrived, and all twelve were thrown away

The message said the file could not be fetched. Twelve of its thirteen pieces
had already arrived perfectly.

A fifty megabyte recording comes down in thirteen slices, and there was **no
retry anywhere in the chain** — not in the browser, not on the server. One
dropped connection out of thirteen requests to the same host discarded the
whole download and reported it as a file that could not be reached. Twice, over
the final twenty-four seconds of a fifty-three minute recording.

Two changes, and either one alone would have fixed it:

* A slice is now asked for up to **three times**, on both sides. A refusal is
  not retried — a refusal is an answer, and asking a host that said 403 to say
  it twice more is just rudeness.
* And if a slice still never comes, **everything but the last scrap of a file
  is a picture of that file**. At 95% or more, the waveform is drawn from what
  arrived and the timeline is scaled so the track's length stays right. Below
  that it is still a failure, because a confident picture of two-thirds of a
  recording is worse than saying so.

## The reason was there the whole time

`upstream-unreachable` — "this site could not reach the file's own server" —
covers a name that would not resolve, a certificate that would not verify, a
timeout, and a connection reset at byte forty million. Four different problems
with four different fixes. cURL names which one **every single time**, and that
sentence was being read into a variable and dropped on the floor.

It travels back now, in a header and in the body, and is shown verbatim:

    this site could not reach the file's own server (slice 13 of 13)
    — cURL error 56: Recv failure: Connection reset by peer

That is the line that should have appeared the first time, instead of the three
explanations that got invented to fill the gap.

## The check was asking the easy question

It only ever requested `bytes=0-1023`, which is why it came back entirely
green about a file whose measurement was failing on the thirteenth slice — and
made the plugin look like it was contradicting itself.

The first kilobyte of a file is the one request that is always cheap, always
cached and always allowed. Proving it works proves very little. The check now
asks for the **tail** as well: a range ending at the last byte, on a connection
the host has already served a dozen times, which is where the interesting
failures actually live.

## Proved, not asserted

The reported failure is reproduced end to end in a real browser against a real
ranged server:

* a slice that fails twice and then works — the measurement must survive it;
* a slice that never comes — a waveform must still come out, with the full
  track length;
* a slice missing from the **middle** — this must still refuse, which is the
  only thing that makes forgiving a missing tail defensible.

Each of the three was verified by switching the fix back off and watching the
test fail.

1232 checks green.

## Still worth doing on your server

**Turn `popen` back off.** It was enabled during this hunt on the theory that
it was the cause; it was not, and the check confirms it is still listed in
`disable_functions` regardless. It runs `ffmpeg`, and there is no `ffmpeg` on
that server to run.
