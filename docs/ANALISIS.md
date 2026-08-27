# Análisis previo: de ZoomSounds a Imagina Player

Este documento resume lo que se revisó antes de escribir una sola línea del
plugin: el reproductor que hay que sustituir, lo que hacen los competidores y en
qué estado está el ecosistema de librerías de reproducción en 2026.

## 1. El plugin actual (DZS ZoomSounds 6.95)

Se descomprimió y se leyó el ZIP del repositorio. Datos objetivos:

| Métrica | Valor |
| --- | --- |
| Ficheros | 814 |
| Peso descomprimido | ~14 MB |
| `audioplayer/audioplayer.js` | 7.223 líneas |
| Skins de reproductor | 10 (`skin-wave`, `skin-pro`, `skin-steel`, …) |
| Librerías propias empaquetadas | contentscroller, isotope, ultibox, farbtastic, fontawesome, dzsselector, dzstabsandaccordions, videogallery… |

La vista de la captura que se aportó es la skin `skin-wave`: forma de onda con
reflejo, botón de play circular, artista sobre título y volumen a la derecha.

### Qué está bien pensado

- La forma de onda se dibuja en **canvas** con dos capas (fondo y progreso), no
  con cientos de nodos DOM.
- El reflejo bajo la onda y las etiquetas de tiempo que siguen al cabezal son
  detalles de diseño que sí merecía la pena conservar.
- Los ajustes se agrupan en "configs" reutilizables, la idea correcta que los
  competidores modernos llaman *presets*.

### Qué no

1. **Todo el reproductor se construye en el cliente.** `_view_playerStructure.js`
   mueve nodos con jQuery (`.after()`, `.wrap()`, `.prepend()`) según la skin.
   El HTML del servidor no es el reproductor: sin JavaScript no hay nada, y el
   navegador reflowea el bloque entero al inicializar.
2. **Dependencia de jQuery** en todo el núcleo, más un `audioplayer.ie11.js`
   heredado que todavía se distribuye.
3. **Los picos de onda se guardan en `wp_options`.** `inc/php/db/pcm-functions.php`
   hace `update_option( 'dzsap_pcm_data_<hash>', … )` — una fila por pista, en la
   tabla que WordPress carga entera en cada petición si la opción es autoload.
   En una web con 300 audios esto es un problema medible.
4. **Los picos se serializan como JSON de flotantes**, unos 2,5 KB por pista
   frente a los ~540 bytes que ocupa el mismo dato como un byte por barra.
5. **Superficie enorme**: galería de vídeo, lightbox, tabs, acordeones, selector
   de color… todo dentro del mismo plugin y todo mantenible por nosotros si se
   hace un fork.

**Conclusión:** un fork obliga a heredar los cuatro problemas estructurales
(1–4) porque están en el centro del diseño, no en los bordes. Reescribir cuesta
menos que desmontar. Además, el cliente confirmó que no necesita compatibilidad
con los shortcodes antiguos: los audios se están migrando de proveedor y los
bloques se van a volver a colocar de todas formas.

## 2. Qué hacen los demás

### Presto Player

Reproductor de vídeo de referencia en WordPress. Bloques nativos de Gutenberg
(migrado a Block API v3), módulos para Elementor, Beaver Builder y Divi, un
"Media Hub" que centraliza los archivos, y presets de reproductor reutilizables.
Fuentes: autoalojado, YouTube, Vimeo, Bunny.net. Está construido sobre Plyr.

**Lo que copiamos:** los presets como entidad de primera clase y el bloque
dinámico (cambiar el preset repinta todos los reproductores publicados sin
volver a guardar las entradas).

### FluentPlayer

Reproductor "de conversión" del ecosistema WPManageNinja. Gratis: autoalojado,
YouTube, Vimeo y audio, presets, capítulos, marca propia y formularios dentro del
vídeo. Pro: playlists, analítica (visualizaciones, tiempo de visionado, retención
de audiencia), contenido temporizado, subtítulos, capas y hosting en BunnyCDN,
Mux, Cloudflare, Gumlet y HLS. Incluye una ruta de migración desde Presto Player.

**Lo que copiamos:** presets reutilizables aplicados "en un clic", memoria de la
posición de reproducción, y la idea de que la analítica es una capa opcional
sobre el reproductor, no parte del núcleo.

### MP3 Audio Player by Sonaar / AudioIgniter

Los dos referentes en **audio** dentro del repositorio de WordPress. Sonaar
aporta ondas, coverflow, reproductor fijo inferior, podcast RSS y WooCommerce;
AudioIgniter aporta playlists ilimitadas y streaming (Icecast/Shoutcast) con una
interfaz mucho más sobria.

**Lo que copiamos:** el reproductor fijo inferior (*sticky*) cuando el usuario se
desplaza, y las playlists como fase siguiente.

## 2b. Inventario real (leyendo su código, agosto 2026)

Con los ZIP de Presto Player 4.4.1, Presto Player Pro y FluentPlayer 1.4.0
delante, esto es lo que tienen de verdad.

### Presto Player 4.4.1

- **Stack:** React + Tailwind (`@bsf/force-ui`, `lucide-react`), compilado con
  `@wordpress/scripts`. Menú de primer nivel, una sola SPA montada en
  `#presto-admin-dashboard`, y las pestañas son enlaces con `?tab=`.
- **Presets:** en **tablas propias** (`Presets`, `AudioPresets`), separadas para
  audio y vídeo, expuestas por REST.
- **Campos del preset de audio:** `skin`, `background_color`, `control_color`,
  interruptores (`play`, `play-large`, `rewind`, `fast-forward`, `progress`,
  `current-time`, `mute`, `volume`, `speed`, `pip`), `reset_on_end`,
  `sticky_scroll` + `sticky_scroll_position`, `on_video_end`,
  `play_video_viewport`, `show_time_elapsed`, `save_player_position`,
  `border_radius`, y `cta` / `email_collection` / `action_bar` (Pro).
- **Skins de audio: 2** — «Basic» y «Stacked». Los de vídeo son 4 (Modern,
  Business, Stacked, Basic).
- **Pantallas de ajustes:** Branding, CSS personalizado, Licencia, MCP (IA),
  Media Hub, Presets, Desinstalar, Analítica, Contribuir.
- **Pro añade:** Bunny CDN, bloque de playlist, autoalojado privado, captura de
  email con ActiveCampaign / FluentCRM / Mailchimp / MailerLite, Google
  Analytics, webhooks y visitas.

### FluentPlayer 1.4.0

- **Stack:** Vue 3 + Element Plus, sobre un mini-framework PHP propio.
- **Presets:** en la opción `fluent_player_presets`. Trae **7 presets** sobre
  **6 skins**: classic, modern, simple, minimal, standard, floating.
- **Forma del preset:** `controls` (backward, forward, play, progress_bar,
  current_time, volume, settings, playback_speed, fullscreen, pip,
  captions_toggle, chapters), `behaviors` (autoplay, muted_autoplay,
  on_video_end, plays_inline, hide_top/center/bottom_controls),
  `styles.captions`, más `email_capture`, `cta` y `action_bar`.
- **Ajustes globales:** general (aspect ratio, preset por defecto,
  `resume_playback`, `pause_others_on_play`, CSS personalizado), YouTube y Vimeo
  (modo privacidad), analítica con limpieza automática, Google Analytics,
  **branding** (brand_color, control_bar_color, play_button_color, logo con URL,
  enlace, posición y ancho, «powered by») y servicio de subtítulos.

### Qué significa esto para nosotros

Sobre **skins de audio** vamos sobrados: nosotros 7, Presto 2, Fluent 6 pero
orientados a vídeo. Donde ellos van por delante es en cosas que no son skins:

| Nos falta | Lo tienen | Coste |
| --- | --- | --- |
| Branding global (logo y colores por defecto del sitio) | Ambos | Medio |
| CSS personalizado desde ajustes | Ambos | Bajo |
| `border_radius` del reproductor | Presto | Bajo |
| Comportamiento al terminar (reiniciar / repetir / parar) | Ambos | Bajo |
| Posición del reproductor fijo | Presto | Bajo |
| Descripción por preset | Fluent | Bajo |
| Varios presets de arranque | Ambos | Bajo |
| Analítica de escucha | Ambos | Alto |
| Playlists | Ambos (Pro) | Alto |
| Captura de email y CTA dentro del reproductor | Ambos (Pro) | Alto |

## 3. El estado de las librerías de reproducción (agosto 2026)

Esto es lo que más condiciona la decisión técnica:

- **Plyr, Vidstack y Media Chrome se han fusionado** en el desarrollo de
  **Video.js v10**, reescrito desde cero. La beta salió el 10 de marzo de 2026 y
  promete bundles un 81 % más pequeños (5 kB gzip en su configuración mínima) con
  soporte de React y TypeScript de primera clase. La disponibilidad general se
  apunta a mediados de 2026 y **a día de hoy la API sigue sin ser estable**.
- **wavesurfer.js v7** es una reescritura en TypeScript con Shadow DOM. Sigue
  siendo la referencia para ondas, pero decodifica el audio completo con Web
  Audio salvo que se le pasen *peaks* precalculados, y su propia documentación
  recomienda precalcularlos para archivos largos.
- **MediaElement.js**, que WordPress todavía incluye en el núcleo, está
  efectivamente congelado.

**Decisión:** no atar el plugin a ninguna librería ahora mismo. Plyr y Vidstack
están en modo mantenimiento de cara a la fusión, y Video.js v10 aún no es
estable. Para audio, un núcleo propio sobre `HTMLMediaElement` + `canvas` son
~5 KB gzip y control total del diseño. Para vídeo, cuando llegue, la capa de
motor permite enchufar hls.js o Video.js v10 sin tocar el resto.

De wavesurfer.js tomamos la lección, no el código: **los picos se precalculan y
se cachean**, nunca se decodifica el archivo en cada visita.

## 4. Resumen de decisiones

| Decisión | Motivo |
| --- | --- |
| Plugin nuevo, no fork | Los problemas de ZoomSounds son estructurales |
| Sin compatibilidad con shortcodes antiguos | El cliente va a recolocar los bloques igualmente |
| Sin dependencias de runtime | 5 KB gzip frente a 200 KB+ de un framework de reproductor |
| Sin jQuery | No hace falta y desaparece del núcleo de WordPress a medio plazo |
| Renderizado en servidor | El reproductor existe antes de que cargue el JS |
| Bloque dinámico | Cambiar un preset repinta lo ya publicado |
| Picos en meta + tabla propia | `wp_options` no es un almacén de datos binarios |
| Picos como bytes en base64 | ~540 bytes por pista en lugar de ~2,5 KB |
| Capa de motor para vídeo | Video.js v10 aún no es estable; que lo sea no debe obligar a reescribir |
