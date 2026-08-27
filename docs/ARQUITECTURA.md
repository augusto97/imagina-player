# Arquitectura de Imagina Player

## Vista general

```
imagina-player.php        Bootstrap: constantes, autoloader, arranque
src/
  Plugin.php              Contenedor de servicios y ciclo de vida
  Settings.php            Opciones globales y presets (única fuente de verdad)
  Assets.php              Registro de scripts y estilos
  Support/Autoloader.php  PSR-4 mínimo (sin Composer)
  Player/
    Attributes.php        Esquema de atributos: bloque, shortcode y REST
    Config.php            Preset + overrides -> configuración efectiva -> CSS vars
  Media/Track.php         Resolución de la pista (adjunto o URL externa)
  Peaks/
    PeaksRepository.php   Almacenamiento y codificación de picos
    PeaksGenerator.php    Extracción con ffmpeg
    PeaksToken.php        Permisos firmados para escritura anónima
  Render/
    PlayerRenderer.php    Markup del reproductor
    Icons.php             SVG en línea
  Blocks/BlockRegistrar.php
  Shortcodes/PlayerShortcode.php
  Rest/PeaksController.php
  Admin/SettingsPage.php
assets/src/frontend/       Núcleo del reproductor (TypeScript, sin dependencias)
assets/src/editor/         UI del bloque (React/@wordpress)
blocks/audio/block.json
build/                     Salida de webpack (versionada: el plugin funciona al clonar)
tests/                     Suite CLI con stubs de WordPress
```

## Principios

**Una sola fuente de verdad para los atributos.** `Player\Attributes::schema()`
define nombre, tipo y valor por defecto de cada atributo. De ahí salen los
atributos del bloque (`BlockRegistrar::block_attributes()`), los del shortcode
(convertidos a `snake_case`) y la sanitización. Añadir una opción es tocar un
único array.

**Los presets mandan, la instancia matiza.** Un preset agrupa skin, colores,
altura y qué controles se ven. Cada bloque elige un preset y puede sobrescribir
valores sueltos. Los interruptores usan tres estados (`''` = heredar, `'yes'`,
`'no'`) porque un booleano no puede expresar "heredar".

**Renderizado en servidor, mejorado en cliente.** El servidor emite el
reproductor completo alrededor de un `<audio controls>` real. El script quita
`controls`, añade `is-enhanced` y toma el mando. Si el JS falla, el audio se
reproduce igual.

**Un CSS para todos.** Los colores viajan como propiedades personalizadas en el
atributo `style` de cada reproductor. Cincuenta reproductores distintos en una
página siguen siendo una sola hoja de estilos.

## Los picos de onda

El coste real de un reproductor de forma de onda es obtener las amplitudes.
Estrategia en tres niveles:

1. **Caché.** `PeaksRepository::get()` busca los picos. Los adjuntos los guardan
   en post meta (`_imagina_player_peaks`), de modo que se borran con el adjunto y
   viajan en las exportaciones. Las URLs externas van a la tabla
   `{prefix}imagina_player_peaks`, nunca a `wp_options`.
2. **Servidor.** Si hay ffmpeg, `PeaksGenerator` lo lanza fuera de la petición
   (`wp_schedule_single_event`), decodifica a PCM mono de 8 kHz por *streaming* y
   mide el pico de cada ventana de 800 muestras. El renderizado de la página
   nunca espera a ffmpeg.
3. **Navegador.** Si no hay ffmpeg, el primer visitante decodifica el archivo con
   Web Audio, dibuja la onda y devuelve el resultado al sitio.

### Por qué la escritura anónima es segura

El paso 3 implica aceptar datos de un visitante sin sesión. El control es
`PeaksToken`: al renderizar un reproductor sin picos cacheados, el servidor firma
con HMAC (`wp_salt`) un permiso que contiene la clave de la pista, la resolución
y una caducidad de una semana. El endpoint `POST /peaks` solo escribe si el token
valida, y además:

- **escritura única**: si ya hay picos para esa clave, la petición no hace nada;
- **bloqueo temporal** para evitar escrituras simultáneas;
- **tope de 2.000 barras** y recorte de cada valor a `0..1`.

Un atacante no puede escribir picos de una pista que el sitio no haya
renderizado, ni sobrescribir los existentes.

### Codificación

Los picos se guardan como un byte por barra (`0..255`) en base64. Una onda de 400
barras ocupa ~540 bytes en lugar de los ~2,5 KB de un array JSON de flotantes, y
va en línea en el HTML: el reproductor dibuja su onda sin una segunda petición.

## El renderizador de onda

`assets/src/frontend/waveform.ts` pinta las barras **una vez** en un canvas
fuera de pantalla cuando cambian el tamaño, los picos o los colores. Cada frame
solo hace dos operaciones: `drawImage` del canvas cacheado y un `fillRect` con
`globalCompositeOperation = 'source-atop'` que tiñe la parte reproducida. Buscar
en una onda de 400 barras cuesta dos llamadas, no cuatrocientas.

El reflejo bajo la onda se dibuja en la misma pasada con `globalAlpha`, y la
proporción es configurable (`wave_reflection`, 0,25 por defecto).

## Rendimiento

- Bundle de front-end: **~5 KB gzip**, sin dependencias de runtime.
- Se encola **solo** en páginas que renderizan un reproductor.
- Inicialización diferida con `IntersectionObserver` (200 px de margen).
- `MutationObserver` para reproductores inyectados por AJAX o scroll infinito.
- `preload="metadata"` por defecto; configurable por preset.
- Un `MutationObserver` global y un `IntersectionObserver` compartido, no uno por
  reproductor.
- Solo un reproductor suena a la vez (registro global de instancias).

## Extensibilidad

Filtros disponibles: `imagina_player_settings`, `imagina_player_skins`,
`imagina_player_attribute_schema`, `imagina_player_resolved_config`,
`imagina_player_client_config`, `imagina_player_render`,
`imagina_player_ffmpeg_binary`. Acción: `imagina_player_booted`.

## Vídeo (siguiente fase)

`Track::is_video()` y el renderizador ya emiten `<video>`, y el núcleo del
reproductor trabaja contra `HTMLMediaElement`, no contra `HTMLAudioElement`. Lo
que falta es la capa de UI (pantalla completa, póster, subtítulos, capítulos) y
un motor enchufable para HLS. La decisión de no depender hoy de ninguna librería
es precisamente lo que permite adoptar Video.js v10 cuando su API se estabilice
sin reescribir el plugin.

## Pruebas

`./tests/run.sh` ejecuta la suite contra stubs de WordPress, sin necesidad de una
instalación. Cubre sanitización, codificación de picos, remuestreo, firma de
tokens, escapado del markup y —con un binario ffmpeg simulado— la extracción de
picos completa.
