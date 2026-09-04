# Imagina Player — 1.40.0

Download **imagina-player-1.40.0.zip** and install it in WordPress under
Plugins → Add New → Upload Plugin.

    SHA-256  4c80d87ee0b22a61942fd029228851cda76770fec97adc30b27b6d9db22905ee

## What this release is

Asked for: "I would like the URL to be taken dynamically from a custom
field — ACF or JetEngine — so that I create a video URL field on a
WooCommerce product and leave the product template built with that dynamic
field."

**A block can now take its file from a custom field of the post it is shown
on.** Open the block's sidebar, then **Dynamic source**, and type the field's
key — `video_url`, say. Place that one block in the product template, and
each product page shows the video named in that product's own field.

- The field may hold a YouTube or Vimeo link, an MP4 or an HLS address, or
  the media library ID of an uploaded file. Every way ACF and JetEngine
  store a file is understood: an address, an ID as a number or as text, or
  an array carrying either.
- Where a product's field is empty, visitors see nothing there — unless the
  block is also given a file of its own, which is then the default for those
  products.
- In the editor, the preview shows the file of the post being edited. In the
  site editor's template, which has no such field, it says which field it
  will read instead of showing a blank.
- The same works for the audio block, for any post type, and in the
  shortcode as `[imagina_player field="video_url"]`.

A key starting with an underscore is hidden meta and is never read, so naming
a key can never print a token or a private address into a page.

## Verified

On a real WordPress 6.8: three posts sharing one block, one with a YouTube
address in the field, one with a media library ID, one with nothing. The
first two play their own file; the third shows visitors nothing and shows an
editor which field it would have read. The editor's preview endpoint shows
the right file for the post being edited and refuses a post the author may
not edit.
