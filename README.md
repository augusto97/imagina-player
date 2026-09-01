# Imagina Player — 1.30.0

Download **imagina-player-1.30.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  251ce3dad13335ea7cde43d763c5db640bf6180b74aa7477b68f1ea162e9418f

## The bars were real. The statistic was wrong.

A fifty-three minute lesson came back as four hundred bars of very nearly the
same height, and the question asked was whether anything had actually been
measured. It was a fair question, and the answer is in two halves.

### They were real

There is now a test that writes audio whose loudness is known second by second,
measures it in a real browser with the shipped code, and checks every bar
against what was written underneath it. Twelve blocks, written and measured:

    written  1.000  0.000  0.500  0.000  0.250  1.000  0.125  0.750  0.000 …
    measured 1.000  0.000  0.500  0.000  0.250  1.000  0.125  0.750  0.000 …

Exact, including three minutes of silence that read as zero and not as a low
hum, and in the order they were recorded rather than shuffled by the slicing.

### And the picture was still useless

Four hundred bars across fifty-three minutes is **eight seconds of audio in
every bar**, and every bar was drawn as the loudest instant inside it. The
loudest instant in eight seconds of anybody talking is a syllable at full
volume. Every bar. All the way across.

Here is that, measured. Eight stretches of speech-shaped audio, every one just
as loud at its loudest, differing only in how much of the time there is any
sound at all — which is the only thing that varies when a person talks, and the
thing the ear hears as louder and quieter:

    talking   5%   10%   20%   30%   50%   70%   90%  100%
    before  1.00  1.00  1.00  1.00  1.00  1.00  1.00  1.00
    after   0.22  0.32  0.45  0.55  0.71  0.84  0.95  1.00

The top row is the comb. It is not a bug in the arithmetic; it is the wrong
question asked four hundred times.

A bar is now the **loudness across its stretch** — which counts the silence
between the words as well as the words — instead of the loudest moment in it.

## Two more things that came out of looking

* The same measure now runs in all three places a waveform is made: the editor,
  the visitor's browser, and the server's ffmpeg. One file draws one picture
  wherever it happened to be measured.
* The editor never scaled its result to fill the height, though the other two
  always had — so the same file measured in two places came out at two
  different heights.

## Your existing waveforms

They were measured the old way and are still drawn — an old picture beats no
picture. Each stored waveform records the version it was measured under, so
**the editor now offers to measure them again by itself**: open the post, and
the notice appears with the button, the same one that worked in 1.29.0. Nothing
has to be deleted by hand.

1249 checks green. Both changes were verified by putting the old measure back
and watching the tests fail.

## Still worth doing on your server

**Turn `popen` back off.** It was enabled during the earlier hunt on the theory
that it was the cause; it was not, and the check confirms it is still listed in
`disable_functions` regardless.
