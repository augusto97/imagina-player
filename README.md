# Imagina Player — 1.15.0

Download **imagina-player-1.15.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  ad4fe95e3ef33ffd22d553c5c1d65f15d919774d75e27c1f4115068c0eb8d220

## What changed in 1.15.0

**A video that follows the reader.** Scroll away from one that is playing and
it detaches into a small card in a corner, keeping its shape, with the controls
on it and a button to send it away — which stays away. The switch existed
before but did the audio version of it: a full-width bar across the foot of the
window, which for a video is a whole picture lying across the bottom of the
screen.

Writing the test for it found a fault it had from the start: a player already
off screen when playback began was never reconsidered, so an autoplaying video
below the fold, or a playlist carrying on to the next track, would play to
nobody.

**Stills on the seek bar.** Point at the bar and the moment under the pointer
appears above it. Give the block a WebVTT storyboard — what most video tools
export — and nothing is downloaded until somebody actually drags the bar, so a
reader who never scrubs pays nothing for it.

**The sizes a visitor actually pays are now checked too**, alongside the source
sizes: 7.4 KB for the bundle and 5.1 KB for the stylesheet, compressed.

904 checks green.
