# Imagina Player — 1.26.0

Download **imagina-player-1.26.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  3c11b0a4c7ddc62798e80c8b4f7554f41e1f140534cfe2e1c3450a8000a92428

## The file host refuses this server

The check answered it:

    head:  FAILED status=403 type=text/html bytes=0
    range: FAILED status=403 type=text/html bytes=1410

The media host gives **this site's server** a 403 and an HTML error page, for
both a HEAD and a ranged GET — while the same file plays perfectly in the
browser.

That difference is hotlink protection. It allows a domain by `Referer`: a
browser on the site sends one, the domain is on the allow-list, the file plays.
A request made by the site's own server sent **none at all**, so it looked like
nobody and was refused.

Which is why "the domain is whitelisted" was true and did not help. The
allow-list had nothing to match on.

The fetch now says which site it is made for, and identifies the plugin rather
than pretending to be a browser.

## Why this took three attempts

The report also says `sapi: litespeed`, and that is the other half.

A web server in front of PHP is entitled to treat a 5xx from its backend as the
backend having failed, and to replace the entire response — reason header and
body alike — with its own error page. LiteSpeed does.

So the refusal that said *"the host answered 403"* was correct, and never
arrived. It reached the browser as a bare 502 with nothing attached, and there
was nothing left to do with it but guess. Twice I guessed wrong, and one of
those guesses led to a server setting being changed that never needed changing.

**Refusals go out as 424 now**, which no gateway rewrites.

## The check now runs it both ways

Anonymously, and saying which site is asking — and prints both lines. That pair
*is* the diagnosis, rather than something to be inferred from a single status
code. It also prints what it identified itself as, so the address can be
allow-listed at the other end.

## Also settled by the report

`popen` really is disabled — it is in `disable_functions`, at the end of the
list, alongside `proc_open` and `escapeshellcmd`. The notice was right, it was
never cached, and enabling it changed nothing. It is safe to put back as it was.

1175 checks green.
