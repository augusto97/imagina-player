# Imagina Player — 1.25.0

Download **imagina-player-1.25.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  0f756cdf00e90788136c414777554fd447c8ec2613b2b07269c6ac514df74dfa

## Stop guessing, ask the server

Two explanations in a row were wrong, and the second one led to a server
setting being changed that never needed changing. Both were the same mistake:
reading a status code in a browser and inventing a story that fits it.

`max_execution_time` is 300, so the timeout story is dead. Every file comes
from the same provider, so the same-domain-versus-bucket story is dead too.

So this release does not contain another theory. It contains a way to find out.

## A check that reports facts

**Settings → Imagina Player → Waveforms → Why a file will not measure.**

Paste the address of a track that has no waveform. The server goes for it and
reports what it sees:

- the status the file's own host gives **this server** — which is a different
  question from what it gives your browser;
- whether that host will serve part of a file, which is what fetching a large
  one in pieces depends on;
- how long each step took;
- and what PHP is actually permitted to do — the live `disable_functions`, the
  running SAPI, the memory and time limits, and what it makes of ffmpeg.

The report is plain text in a box, selectable in one go, because its purpose is
to be sent to somebody who can read it.

**Reaching the check is itself a result.** It has the same shape as the route
that fetches a remote file — a URL inside a query string, which is a shape
firewalls and security plugins are suspicious of. If the check answers, the
request reaches PHP. If the check itself returns a gateway error, something in
front of WordPress is answering and no PHP setting will change that.

### On the ffmpeg notice

It is not cached and never was — it reads `disable_functions` each time it is
shown. If it still says popen is disabled, then the PHP that runs WordPress
still has it disabled, whatever was edited. A `php.ini` changed for the command
line does not affect the one serving pages. The check now prints both the
setting and the SAPI, so this is answered with evidence rather than argument.

## And the message that started it

A gateway error carrying none of this plugin's own reasons did not come from
this plugin — every refusal it makes says which step gave up. Something between
the browser and WordPress answered instead, and which of those it is cannot be
told apart from a browser.

That is all the message claims now, plus where to look.

1168 checks green.
