# Imagina Player — 1.32.0

Download **imagina-player-1.32.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  47feff73a128e5466117bbbc175e36f37eae61aa3ff4466ba88d0541c4d1f47c

**Install this one.** 1.31.0 has a bug that stops waveforms being saved at all.

## Your console found a bug I shipped four hours ago

    GET /wp-json/imagina-player/v1/peaks?key=url_9b6d… 404 (Not Found)

1.31.0 added a column to the waveforms table. **Nothing ever created it.**

There is a routine whose entire job is to catch up a site whose stored version
is behind the code, and whose own comment explains why it exists — the
activation hook does not fire for a plugin uploaded, copied into place or
deployed over FTP, which is how this one reaches nearly every site that runs
it. It did not touch the tables.

So the code asked for a column that was not there. Every read failed. Every
write failed.

And it was invisible from the editor, which is the worst part: the editor draws
the waveform it has just measured, so measuring looked perfect while nothing
reached the database. The only symptom was the one you saw — a 404 on the front
end, and then every visitor downloading fifty megabytes to work out the same
picture again, on every page view. That is also why it took "un rato" to load.

A failed write now rebuilds the table and tries once more, instead of reporting
a success it did not have.

### The check that should have caught it

It now reads the columns the code writes and reads **out of the code**, reads
the columns the table declares **out of the schema**, and requires that they
agree — and separately, that the upgrade routine applies the schema at all, and
does it before marking the site up to date. Both halves of the bug were put
back and both are caught.

## The horizontal scrollbar

The loading sheen slides a full width in each direction, inside a box that
clipped nothing. So for the twenty seconds it ran, a whole page width hung off
the side and the scrollbar appeared, grew and shrank in time with it.

Measured across every skin at 320, 360, 414 and 768 pixels: **320px of overflow
at 320px wide.** Now none. The test pins the animation at each end of its travel
instead of trying to catch it mid-slide, so it cannot pass by luck.

## And the answer to the thing that started all of this

    range-tail: FAILED error=cURL error 18: transfer closed with 4050 bytes remaining

**There it is.** That is what was killing the measurement on slice 13 of 13 for
three weeks, finally with a name: Publitio answers a range that ends at the last
byte of the file, promises a length, and closes the connection four kilobytes
short.

Nothing to fix on your side. The retries and the missing-tail tolerance from
1.29.0 are precisely what get past it — which is why your waveform works now.
So the check reports that step as **coped** rather than FAILED, with the cURL
error kept beside it. A red line next to a waveform that works is its own kind
of wrong answer, and this thread is long enough. Any *other* failure of that
step is still a failure.

1305 checks green.

## Still worth doing on your server

**Turn `popen` back off.** Confirmed again by your own report: still listed in
`disable_functions`, and `ffmpeg` reports `processes-disabled`. It was never
the cause of anything here.
