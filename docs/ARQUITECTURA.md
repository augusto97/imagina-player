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
  Admin/Dashboard.php      Menú y punto de montaje de la app de ajustes
assets/src/frontend/       Núcleo del reproductor (TypeScript, sin dependencias)
assets/src/editor/         UI del bloque (React/@wordpress)
assets/src/admin/          Pantalla de ajustes (React, diseño propio)
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

**Siete skins, tres disposiciones.** Un skin es una maquetación, no una paleta:
los colores salen del preset. `Player\Skins` dice de cada uno si dibuja onda, si
esa onda va espejada, y con cuál de las tres disposiciones se monta —`stacked`,
`card` o `inline`—. Esas disposiciones cambian el **orden del DOM**, no solo el
CSS: una portada que encabeza una tarjeta no puede ser el mismo nodo que va
dentro de una fila de controles, y reordenar solo con CSS deja el orden de
lectura mal para un lector de pantalla.

**La marca es el punto de partida, no una imposición.** Los colores de
*Branding* son de los que arranca un preset nuevo. No repintan los que ya
existen: eso reescribiría en silencio trabajo que alguien ya hizo.

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
   Web Audio, dibuja la onda y devuelve el resultado al sitio. **Solo para
   archivos cortos**: `decodeAudioData` expande el audio a PCM en memoria, y una
   grabación de 76 minutos en estéreo son ~1,6 GB. El tamaño se comprueba antes
   de descargar nada y por encima del límite (25 MB por defecto) ni se intenta.

Cuando no hay onda por ninguna de las tres vías, el reproductor dibuja una barra
de progreso limpia en lugar de barras de relleno: un estado degradado tiene que
parecer intencionado, no roto. Y para las grabaciones largas está el botón
**Generar ondas pendientes** en Ajustes, que no depende de que WP-Cron se
dispare.

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

## Colores: tres cosas distintas

Se confundieron una vez y salió caro, así que quedan separadas por diseño:

- **El cromo del plugin** (`assets/src/admin/admin.scss`) lleva el color de
  Imagina. No cambia jamás por lo que haga un cliente con su reproductor. El
  acento es lo bastante brillante como para que el blanco encima dé 2,2:1, así
  que los rellenos llevan texto oscuro y lo que *es* texto usa la variante
  profunda. Hay un test que mide el contraste real en el navegador.
- **Los valores de fábrica del reproductor** son neutros a propósito: una
  instalación nueva no debe llegar vestida con la marca de otro cliente.
- **Los colores de cada preset** son del cliente, y mandan sobre todo lo demás.

## Rendimiento

- Bundle de front-end: **~5 KB gzip**, sin dependencias de runtime.
- Se encola **solo** en páginas que renderizan un reproductor.
- Inicialización diferida con `IntersectionObserver` (200 px de margen).
- `MutationObserver` para reproductores inyectados por AJAX o scroll infinito.
- `preload="metadata"` por defecto; configurable por preset.
- Un `MutationObserver` global y un `IntersectionObserver` compartido, no uno por
  reproductor.
- Solo un reproductor suena a la vez (registro global de instancias).
- Sin desbordamiento en móvil: `tests/test-responsive.php` mide los siete skins
  a 320, 360, 414 y 768 px con todos los controles activos. La clave fue que el
  meta tenga base `0` — con una fila que envuelve, un elemento con base
  automática salta de línea *antes* de encogerse.

## Extensibilidad

Filtros disponibles: `imagina_player_settings`, `imagina_player_skins`,
`imagina_player_attribute_schema`, `imagina_player_resolved_config`,
`imagina_player_client_config`, `imagina_player_render`,
`imagina_player_ffmpeg_binary`. Acción: `imagina_player_booted`.

## La pantalla de ajustes

`src/Admin/Dashboard.php` solo pone el punto de montaje y los datos de arranque;
todo lo demás es `assets/src/admin/`, una aplicación React con su propio sistema
de diseño —ni un `form-table` de WordPress—. Lee y escribe por REST
(`Rest\SettingsController`).

La vista previa —tanto la de esta pantalla como la del bloque— es un iframe que
carga el **bundle real** del front-end sobre markup del **renderizador real**,
servido por `POST /preview`. Dos motivos: lo
que ves es lo que se publicará, en lugar de una imitación que se desincroniza
cuando cambia el renderizador; y el CSS del panel no puede colarse dentro y
favorecer el resultado.

Dos decisiones que costaron una versión cada una:

**Una sola paleta clara.** La primera versión seguía `prefers-color-scheme`, así
que con el navegador en modo oscuro *esta* pantalla se ponía oscura mientras el
resto de wp-admin seguía claro — y el CSS de WordPress, que da por hecho un
fondo claro, seguía pintando encabezados oscuros encima. WordPress no tiene modo
oscuro que seguir; que un plugin se lo invente para una sola pantalla desentona
aunque sea legible.

**Todos los colores de texto se declaran, nunca se heredan.** Es lo que dejaba
que el CSS de wp-admin se colara en primer lugar.

## Medios protegidos

`src/Protection/` implementa entrega por enlace firmado, y se apoya en tres
piezas separadas a propósito:

- **`Vault`** decide dónde vive el archivo. Proteger un adjunto lo *mueve* a
  `uploads/imagina-protected-<hash>/` y repunta `_wp_attached_file`. El hash sale
  de las salts del sitio, así que la ruta no es adivinable en servidores donde el
  `.htaccess` que se escribe allí no se aplica.
- **`ProtectedMedia`** decide quién puede escuchar. El token dice *qué* archivo se
  pide, nunca *si* se puede: caducidad, sesión, usuario, red y el filtro
  `imagina_player_can_stream` se evalúan de nuevo en cada petición, así que un
  enlace filtrado se topa con las mismas comprobaciones.
- **`StreamServer`** entrega los bytes. Resuelve `Range` para que el scrub
  funcione, y puede delegar en `X-Accel-Redirect` o `X-Sendfile` para no dejar un
  worker de PHP ocupado durante toda la reproducción.

Los tokens se emiten desde el inicio de una ventana fija, no desde «ahora», para
que una página cacheada sirva la misma URL a todo el mundo; y si aun así caduca,
el reproductor pide una nueva a `/stream-url` y reanuda donde estaba.

`Support\Signature` firma tanto estos enlaces como los permisos de escritura de
ondas, con un `$context` distinto en cada caso: un token de un propósito nunca
valida para el otro, y hay un test que lo comprueba.

### Comprobar que protege de verdad

Todo lo anterior es la *intención* del plugin. Que el servidor la aplique es
otra pregunta, y tiene una forma conocida de fallar: el vault escribe un
`.htaccess`, nginx no ha leído uno en su vida, y el plugin se declararía sano
mientras cada archivo está en abierto detrás de un nombre de carpeta.

`Protection\SelfCheck` pregunta al servidor en vez de preguntarle al código.
Escribe un señuelo dentro del vault, lo pide por HTTP real sin cookies —igual
que cualquier desconocido— y lee la línea de estado. Un 200 con el contenido
del señuelo significa que las reglas de denegación no se están aplicando, diga
lo que diga la pantalla de ajustes. Después borra el señuelo.

Las comprobaciones de token hacen lo mismo contra el endpoint real: sin token,
con la firma alterada, caducado y emitido para otro archivo. Y la otra mitad de
la afirmación —que un enlace válido *sí* reproduce—, porque una protección que
rompe la reproducción tampoco sirve.

Tres decisiones que hacen que el resultado se pueda creer:

- Si el sitio no puede hacerse una petición a sí mismo, el resultado es «no se
  pudo confirmar», nunca «correcto». Un check que da verde cuando no ha podido
  medir nada es peor que no tenerlo.
- El señuelo es un archivo de usar y tirar, no una grabación del cliente: la
  comprobación funciona en un sitio que aún no ha protegido nada, y un verde
  nunca depende de que hubiera algo que perder.
- Si no hay nada protegido, las comprobaciones de token se declaran *no
  ejecutadas*, no superadas.

`tests/test-selfcheck.php` lo ejecuta contra servidores web reales: el servidor
integrado de PHP como suplente fiel del caso nginx —tampoco lee `.htaccess`— y
un router que deniega la ruta del vault como servidor bien configurado.

## Vídeo

`Track::is_video()` decide el camino, y lo decide el **medio**, no el skin: todo
skin de audio coloca una fila de controles junto a una onda, y un vídeo los
necesita encima de la imagen. Por eso el renderizador elige el layout
`theater` en cuanto el archivo es vídeo, sin preguntar por el skin.

`PlayerRenderer` monta ese layout a partir de piezas con nombre, igual que las
otras tres. Las nuevas son `part_poster`, `part_big_play`, `part_video_controls`
y `part_layers`, todas dentro de `.imgp__stage`, que es quien lleva la relación
de aspecto: la caja tiene su altura definitiva antes de que llegue un solo byte
de vídeo, y la página no salta cuando llega (eso es lo que mide el CLS de
PageSpeed).

Tres costuras para que crecer no signifique reescribir:

- **`imagina_player_video_controls`** — la barra se construye desde una lista de
  botones, así que subtítulos, calidad o capítulos son una entrada más y un caso
  en el módulo, no una reescritura del markup.
- **`imagina_player_video_layers`** — un contenedor vacío por encima del vídeo y
  por debajo de los controles. Existe ya, vacío, porque capítulos, CTA y captura
  de email necesitan todos el mismo contexto de apilamiento, y añadirlo después
  significaría renumerar todos los `z-index` de la hoja de estilos.
- **`imagina_player_video_guards`** — los atributos de endurecimiento, en un
  sitio.

### Peso

El cromo de vídeo vive en `assets/src/frontend/video.ts`, y solo se llega a él
por un `import()` dinámico desde el núcleo. Webpack lo emite como un chunk
aparte (`build/imagina-video.js`, ~3.7 KB): **una página que solo tiene audio
nunca lo pide.** `tests/test-payload.php` lo fija con presupuestos por archivo y
buscando cadenas que solo existen en el módulo dentro del bundle principal —
convertir el `import()` dinámico en uno normal no rompe ningún comportamiento,
así que sin ese test pasaría inadvertido. Lo comprobé haciéndolo: el bundle
creció y el chunk desapareció.

Webpack calcularía por su cuenta dónde está el chunk, leyendo
`document.currentScript.src` — y **lanza una excepción** cuando no hay URL que
leer, que es exactamente lo que pasa si un plugin de optimización inserta el
bundle dentro de la página. El reproductor moría antes de dibujar nada. Por eso
`output.publicPath` está fijado a cadena vacía (no emite detección alguna) y
`frontend/public-path.ts` pone el valor real desde lo que dice WordPress. Si el
chunk aun así no carga, el módulo devuelve los controles nativos: el visitante
se queda con un reproductor feo, no con un rectángulo muerto.

### Que no se lo lleven

Por capas, y en orden de lo que realmente hace trabajo:

1. El archivo **no está en una carpeta pública** (el vault) y su URL **caduca**.
   Esto es lo único que protege de verdad.
2. `controlslist="nodownload"`, `disableremoteplayback` y el menú contextual
   propio quitan el camino fácil: el botón de descarga del navegador, «Guardar
   vídeo como» y mandar la URL cruda a un Chromecast.
3. Nada de esto impide grabar la pantalla. Tampoco lo impide el DRM. Si en algún
   sitio decimos lo contrario, es un error de nuestra documentación.

Si el preset ofrece descarga, la capa 2 no se aplica: ofrecer un botón de
descarga y a la vez esconder el del navegador es teatro.

### Subtítulos y capítulos

`<track>` lee WebVTT y nada más, pero lo que la gente tiene es SRT: es lo que
produce cualquier servicio de transcripción. Un plugin que solo acepta VTT en
realidad le está diciendo al usuario que se busque un conversor. `Media\Captions`
convierte al leer (`Rest\CaptionController`, cacheado un día) en vez de al
subir, para no acabar con dos ficheros que mantener en sincronía ni una
migración de todo lo que ya está en la biblioteca. Un `.vtt` va enlazado
directo; solo el SRT da el rodeo.

La conversión merece pruebas duras: una línea de tiempos mal formada en un VTT
no falla en voz alta —el navegador descarta esa señal y, en casi todos los
motores, **todas las siguientes**—, así que un conversor «casi bien» produce un
vídeo cuyos subtítulos se paran a la mitad. Por eso los tiempos se reconstruyen
desde los números parseados, no se buscan y reemplazan.

`Captions::read()` es alcanzable desde un endpoint público, así que nunca
convierte una URL en una ruta que pueda apuntar fuera de los uploads del sitio:
prefijo, extensión (`vtt`/`srt`) y `realpath` contenido dentro del directorio,
las tres cosas. Hay un test que sube por `..` **con extensión `.vtt`**, porque
la primera versión de esa prueba pasaba por la comprobación de extensión y no
por la de contención — y no sabía distinguirlo.

Los capítulos van en línea como `data:` URI: son unos cientos de bytes, una
petición costaría más que el contenido, y así funcionan en un sitio cuya REST
API esté detrás de un plugin de seguridad. Se ordenan en el servidor porque un
VTT tiene que ser monótono y un navegador descarta en silencio las señales que
llegan fuera de orden.

### HLS

`hls.js` (1.7.1) va en un chunk propio, detrás de su propio `import()`
dinámico, y solo se pide cuando la fuente es `.m3u8` **y** el navegador no sabe
reproducirlo por su cuenta. Safari y iOS lo reproducen de forma nativa —en iOS
es la única forma, porque MSE no existe allí— así que no pagan nada. Cargar 400
KB para hacerlo peor que el navegador no es una opción.

Detectar HLS es por extensión, no por MIME: `.m3u8` no es un tipo de subida que
WordPress conozca, así que `wp_check_filetype()` no dice nada de él. Sin eso, un
stream se renderizaba como reproductor de **audio** — una fila de controles sin
imagen. Lo encontró un test, no una revisión.

**Y lo que de verdad importa:** un stream protegido no es un fichero, es un
manifiesto y unos cientos de segmentos. Firmar solo el manifiesto no protege
nada, porque las direcciones de los segmentos están dentro de él en texto
plano. `hls.js` resuelve esas direcciones contra el manifiesto pero **no
arrastra su query string**, así que la firma se vuelve a poner en cada petición
mediante `xhrSetup` — y solo en el mismo origen, porque mandar el token del
sitio al host que el manifiesto mencione sería regalarlo.

`tests/test-hls.php` levanta un servidor real que apunta cada petición que
recibe y comprueba que **todos** los segmentos llevaban el token. Quitar el
`xhrSetup` hace fallar el test con la lista de segmentos desnudos; lo comprobé.

## Capas de conversión

Tres tipos, porque responden a tres preguntas distintas: **cta** interrumpe
—pausa y cubre—, **bar** hace la misma oferta sin interrumpir, y **email** es
una puerta. `Player\Layers` las sanea; `PlayerRenderer::part_layer()` las
dibuja **en el servidor y ocultas**, no las construye JavaScript: así la oferta
existe en la página para cualquier cosa que lea páginas en vez de ejecutarlas.
Lo único que hace el runtime es decidir cuándo dejar de ocultarlas.

No son solo de vídeo, y por eso no viven en `video.ts`: una puerta de email a
dos tercios de un podcast es exactamente la misma función.

### El endpoint de captura no lleva nonce, a propósito

Sería lo obvio y estaría mal: el formulario se imprime dentro de una página que
una caché de página entera sirve a todo el mundo durante horas, así que el
nonce impreso está caducado para todos menos el primer visitante — y para un
visitante no identificado es el mismo valor para todos, es decir, no es un
secreto.

Lo que hace el trabajo es más barato y más honesto: un campo que ninguna
persona ve (relleno = script, y se responde **200** sin escribir nada, porque
decirle a un bot que lo pillaste solo le enseña qué cambiar), un límite de
cinco envíos por dirección en diez minutos, y el hecho de que lo peor que puede
pasar es una fila que el dueño del sitio puede borrar.

El límite va **por dirección, no por IP**: una IP la comparte toda una oficina
o toda una red móvil, así que limitar por IP dejaría fuera a un edificio entero
por culpa de una persona.

`{prefix}imagina_player_leads` es tabla propia, no CPT: son filas, no
contenido. Nunca se editan, nunca se muestran en el front, y pueden ser
muchas — un CPT las metería en `wp_posts` junto a las páginas reales del sitio
y toda consulta de listado lo pagaría. `email` y `list` son únicos **juntos**:
la misma persona puede apuntarse al curso y a la newsletter.

En la exportación, una celda que empiece por `= + - @` se prefija con comilla:
una dirección `=HYPERLINK(...)` es un ataque real contra quien abre el CSV, y
quien lo abre es el dueño del sitio.

## Playlists

Un bloque aparte (`imagina/playlist`), porque su forma es distinta: una lista
de pistas y un reproductor. El reproductor es **el de siempre** —mismo
renderizador, mismos skins, misma protección—; lo que añade la playlist es la
lista al lado y la capacidad de cambiar lo que suena sin reconstruir nada.

La propiedad que sostiene todo el diseño: **cada elemento es un enlace a su
propio archivo**. Antes de que corra una línea de JavaScript, pinchar una pista
la reproduce, que es lo que pide quien pincha una pista de una lista. El
runtime intercepta el clic y se lo pasa al reproductor que ya está en la
página — así el volumen y la velocidad que eligió el oyente sobreviven al
cambio, cosa que no pasaría reconstruyendo el reproductor en cada clic. Un clic
con Ctrl o Cmd no se intercepta: eso es pedir abrirlo en otro sitio.

`Player::loadTrack()` cambia lo que suena y deja el reproductor en paz. Los
picos viajan con cada pista cuando el servidor ya los tiene medidos: la
alternativa es una petición por pista mientras el oyente recorre un álbum, y
todo el pipeline de ondas existe precisamente para evitar eso.

### Qué pesa cada cosa

Comprimido, que es lo que viaja:

| | |
| --- | --- |
| cualquier página con reproductor | 7 KB |
| página con vídeo | 9 KB |
| + capas de conversión | +1.5 KB |
| + playlist | +0.7 KB |
| página con stream, fuera de Safari | 183 KB |

Cada función opcional es su propio chunk, y `tests/test-payload.php` comprueba
que ninguna se cuela en el paquete que carga todo el mundo — buscando cadenas
que solo existen en cada módulo dentro del bundle principal, y comprobando
además que **sí** están en su chunk, para que el test no pase porque alguien
borró la función en vez de diferirla.

`hls.js` no tiene presupuesto de bytes, porque su tamaño no lo elegimos
nosotros y no hay forma más pequeña de reproducir streaming adaptativo. Lo que
sí tiene es una regla dura sobre **quién lo paga**, y `tests/test-payload.php`
la comprueba buscando símbolos internos de la librería en los otros tres
paquetes.

## Pruebas

`./tests/run.sh` ejecuta la suite contra stubs de WordPress, sin necesidad de una
instalación: 528 comprobaciones. Cubre sanitización, codificación de picos,
remuestreo, firma de tokens, escapado del markup, el movimiento real de ficheros
dentro y fuera del vault, y —con un binario ffmpeg simulado— la extracción de
picos completa.

`tests/test-frontend.php` carga el reproductor en Chromium y comprueba estados
que solo existen en el DOM: que la animación de «analizando» no se queda colgada,
que sin onda se degrada a barra de progreso, y que el canvas efectivamente pinta.
Se salta solo si no encuentra un navegador.

`tests/test-package.php` construye el ZIP de distribución, lo extrae y arranca el
plugin **en un proceso aparte**, con solo el contenido del archivo en el
autoloader: un fichero que se quede fuera del ZIP falla aquí y no en el sitio de
un cliente. `tests/test-release.php` comprueba que los cinco sitios donde vive el
número de versión digan lo mismo.

`tests/test-stream-http.php` va más lejos: levanta el servidor de streaming
sobre el servidor web integrado de PHP y lo interroga con peticiones HTTP
reales, porque los 206, la aritmética de `Content-Range` y los cuerpos byte a
byte no se pueden comprobar llamando a funciones.
