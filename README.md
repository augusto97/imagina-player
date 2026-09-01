# Imagina Player — 1.27.0

Download **imagina-player-1.27.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  eecce6042ae19a3db5a6a3b09056228917d14a64f48c2b4643c0604c7fcc9414

## 206 is a success, and it was being treated as a refusal

The `Referer` fix in 1.26.0 worked. The check says so:

    head-as-this-site: ok status=200 length=50783776 acceptsRanges=bytes
    range:             ok status=206 contentRange=bytes 0-1023/50783776

The server can fetch the file. What refused it was this plugin.

A server answering a `Range` request correctly answers **206**. The test for
whether the fetch had worked demanded exactly **200** — written before there
were ranges, and left alone when they were added in 1.24.0.

So from the moment large files started coming through in slices, every one of
them was refused, and the message said the media host had refused with a 206.
Which is a success code, and reads as nonsense because it is.

## Why the suite stayed green through all of it

There was no test that ran the route with a range on it. Adding ranges and
breaking every ranged fetch looked like a passing suite, because nothing
exercised the combination.

Adding that test found a second gap. The route makes two requests — a HEAD
first, then the download — and the HEAD check catches an outright refusal
before the download starts. So the check *after* the download is only ever
reached when the two answers differ, and the test harness could not make them
differ. Replacing that check with `if (false)` passed the entire suite.

They can now answer independently, and "a download refused after an allowed
HEAD" is checked on its own.

1180 checks green.
