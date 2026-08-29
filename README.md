# Imagina Player — 1.18.0

Download **imagina-player-1.18.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  328647c82c508eb8a0721de1beecb768d8bd1ea8a84fab395ceb42f746d1517c

## What changed in 1.18.0

**The plugin speaks Spanish.** 471 strings, a complete es_ES translation, and
the compiled catalogue shipped in the archive — the admin screens, the block
panels, the notices and the player's own labels.

## Four ways it would not have

Translations are the one feature that fails by doing nothing. Nothing throws;
the interface simply carries on in English. Four separate breaks were on the
path between a finished translation and a Spanish page, and none of them
raises an error:

**The plugin never called load_plugin_textdomain.** WordPress opens a plugin's
own catalogue only when it is told where it is. The .mo would have shipped in
every release and never been opened.

**The release archive had no languages folder** in its list of contents, so
even a correctly loaded text domain would have found nothing on an installed
site.

**Every bundle was handed the whole 45 KB catalogue**, and the front-end
bundle — which contains no translated string of its own, since its handful
come from PHP — was told to fetch one on every page view. Each catalogue now
carries only the strings its own sources use, and the front end has no file.

**The caption search's three strings** were missing from the payload PHP sends
the player, so that box would have stayed in English on a Spanish site.

## How it is checked

The test does not stop at the files. It compares the template with the source,
the translation with the template, the placeholders inside every translated
string, and the compiled catalogue byte for byte against a reader written from
the MO format rather than from the writer. Then it loads that catalogue into
the harness and renders a real player, reading the Spanish back out of the
markup.

The harness's own translation stubs returned their input unchanged until now,
which is why nothing in the suite could tell a loaded catalogue from an
unloaded one.

## Also in this release

**Corner radius per block**, not only on the preset.

**Caption search** — a box that finds the moment a word is said and jumps to
it, reading the subtitles the video already carries. No index, no extra
request. Accents and case fold, so *pagina* finds *página*, while ñ is left
alone, because in Spanish it is a letter and *año* is not *ano*.

## For translators

No gettext and no msgfmt needed. Three scripts in bin/: make-pot.php extracts,
merge-po.php brings a translation up to date without losing what is already
there, make-mo.php compiles.

1023 checks green.
