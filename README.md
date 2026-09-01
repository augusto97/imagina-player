# Imagina Player — 1.35.0

Download **imagina-player-1.35.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  3a3611dcdde4d4bb74b55ebd43a3520c7dcd81fe1827fd23edb0b6ca79563b66

## You asked two questions. Both answers were no.

**Does it generate all of them, with the method we built?** No. On your site it
generated *nothing at all*, and said so as a success.

**Does it report which failed and why?** No. A count, and the first failure's
raw internal tag.

## Why it found nothing on your site

It asked the media library for attachments with **no stored waveform**. That
quietly excluded two whole groups:

* A file measured by an older version *has* a stored waveform, so it was
  skipped — and those are precisely the ones worth doing again, now that a bar
  means loudness rather than the loudest instant.
* A track played from an address rather than an upload has **no row in the
  media library at all**. Your entire library is Publitio addresses. So the
  button looked, found nothing, and reported success.

It now includes anything not measured the way this version measures, and finds
tracks played from addresses **inside the posts that play them** — which is the
only place they exist, because a waveform is stored under a hash of the address
and a hash does not run backwards. It reads block attributes rather than
rendered HTML, so the address is the exact string the player will look under,
and it follows playlists and blocks nested inside columns or groups.

## And it could not have measured them anyway

"Try the file directly, and fall back to this site's own doorway" lived inside
the editor's notice. The settings screen fetched every file **directly** — so
any host that does not let another domain read its files, which is most of them
and is the entire reason the doorway exists, failed at the first request.

One implementation now, used by both screens.

A waveform measured for an address from that screen was also being stored
against attachment zero, which is nothing at all. The address goes with it now.

## The report

Before:

    9 of 20 generated. The rest failed — the first: proxy-upstream-403|slice 13 of 13

That says neither which eleven files were left nor what to do about any of
them, and eleven failures rarely share one cause — one behind hotlink
protection, one that is not audio, one on a host that truncates transfers are
three different jobs.

Now every file that failed is listed by name with its own reason, in the same
words the editor uses:

> **No se pudieron medir 3 archivos:**
> **1.1 EL CAMINO DEL AMOR** — el servidor donde está el archivo también le
> respondió 403 a este sitio; revisa la protección contra enlazado… (slice 13 of 13)
> **2.4 PERDONARTE** — la dirección no devuelve un archivo de audio o vídeo

Both gaps in the list were confirmed by restoring them and watching the tests
fail.

1343 checks green.

## What to do

Install, then **Ajustes → Imagina Player → Ondas → Generar las ondas que
faltan**. This time it will actually find your Publitio tracks, measure them
through the doorway with the retries, and tell you file by file if any of them
could not be done.
