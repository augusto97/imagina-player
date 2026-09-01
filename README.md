# Imagina Player — 1.31.0

Download **imagina-player-1.31.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  bca2ff179c7946f8548c51f70922450aaeba862653da1872532cdaea851b5ad1

## You were right. 1.30.0 did nothing for you.

The new measure was real and the button that would have let you use it did not
exist. Two reasons, and the second is the worse one.

### The table had nowhere to write it

1.30.0 marked each stored waveform with how it was measured, so an old one
could be offered for redoing. The waveforms table has no such column, and the
code that reads a row filled the field in from the current constant instead of
from the row:

    'version' => self::FORMAT_VERSION,

Every stored waveform therefore claimed to have been measured whichever way was
current. Nothing was ever out of date. The offer was unreachable from its first
line — for your file in particular, which lives in that table because it is a
URL rather than a library upload.

The column exists now, is written, and is read. Rows written before it report
the older measure, which is what they are.

### And there was nowhere to ask anyway

The notice disappeared entirely the moment every track had a waveform. So
measuring was something the editor did to you when it judged a file lacking,
and never something you could request — which is no use at all when the file
*has* a waveform and the waveform is the thing that looks wrong. You took the
audio out and put it back trying to provoke the button. There was no button to
provoke.

Now, in the block's sidebar:

* **"Volver a medir esta onda"** — always there, whenever a track has one.
* a separate notice for waveforms measured the older way, with **"Volver a
  medirla"**.
* the original warning, unchanged, for a file with no waveform at all.

## Why the tests said it worked

Because they never stored a waveform. The check called `is_current()` on an
array built inside the test itself, and confirmed the controller mentions that
function. Both passed while the feature did not exist.

It could not have done better: the test harness answered every database read
with null and threw every write away, so no test in this project could store
something and read it back. That is the gap the bug lived in.

It stores now. The test writes a waveform, reads it back, sets the row to what
it looks like after an upgrade, and asks the editor's own endpoint what it would
put on screen. Putting either half of the bug back makes it fail — both were
checked that way.

1256 checks green.

## What to do

Install, open the post, and the button is in the block's sidebar under the
waveform. Press it once and your lesson gets measured with the new measure —
the one that shows pauses and changes in delivery instead of a flat comb.

## Still worth doing on your server

**Turn `popen` back off.** It was never the cause of any of this.
