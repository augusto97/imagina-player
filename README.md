# Imagina Player — 1.34.0

Download **imagina-player-1.34.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  72706344e8e9a7a4ee45d1c2c36217d6b07ee5644630cff6e847b2772917f040

## That button was not a missing translation

It was translated. It has been translated since 1.31.0. It was in the `.po`, it
was in the JSON the editor loads, and it still came out in English.

**Every string with a plural has been in English since the translation
generator was written.**

A `.mo` file keys a plural entry as `msgid` + NUL + `msgid_plural`, and uses
`\4` to separate a context from its msgid. The JSON that `wp.i18n` reads keys a
plural by the msgid **alone**, with the forms in the value, and uses `\4` only
for context. The generator ran the two formats together — it turned that NUL
into a `\4` — and produced a key meaning "the singular, in the context of the
plural". Nothing ever asks for that, so nothing ever found it.

Singular strings were unaffected. That is why it lasted this long: nothing
looked broken, because everything that was not counted was fine. It took one
button sitting in the middle of an otherwise Spanish panel.

Fixed, and all four affected strings now come back in Spanish.

### The check that would not have caught it

A test that reads the generated JSON and decides it looks correct is written by
the same understanding that produced the file. It would have agreed with the
bug, confidently, and told you the translations were fine.

So it does not read the file. It loads each catalogue into the **actual
`@wordpress/i18n` library the browser runs** and asks it for the strings —
singular and plural, one and several — and checks that what comes back is not
the English it was given. Putting the old key format back fails it.

## And the link is a button

You were right that it disappeared. Blue underlined text in a column of form
controls reads as a caption for the field below it, not as something you can
press. It is a proper button now, in its own block with a rule above it, and a
line underneath saying when you would actually want it:

> **Volver a medir esta onda**
> Solo hace falta si la forma se ve mal, o después de cambiar el número de barras.

1325 checks green.
