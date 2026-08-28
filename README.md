# Imagina Player — descargas

Esta rama contiene únicamente el plugin empaquetado y listo para instalar. El
código fuente está en la rama de desarrollo.

## Instalar

1. Descarga `imagina-player-1.11.0.zip`.
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin**.
3. Sube el ZIP y actívalo.
4. Configura los presets en **Imagina Player** (menú lateral), o desde el enlace
   **Ajustes** de la fila del plugin en **Plugins → Plugins instalados**.

## Los bloques

En el insertador de Gutenberg, buscando «imagina»:

| Bloque | Para qué |
| --- | --- |
| **Imagina Audio Player** | un audio, con onda |
| **Imagina Video Player** | un vídeo o un stream HLS |
| **Imagina Playlist** | varias pistas, en lista o cuadrícula |

## De dónde sale la onda

Tres niveles, en este orden:

1. **ffmpeg en el servidor**, si lo hay.
2. **El navegador del primer visitante**, por debajo de 25 MB.
3. **Tu navegador, desde el editor.** Sin límite y sin depender de ffmpeg.

## De dónde sale el nombre

Etiquetas del propio fichero → título en la biblioteca → nombre del fichero. Lo
que escribas en el bloque gana siempre. Se ajusta en **Detalles de la pista**.

## Versión actual

| | |
| --- | --- |
| Versión | `1.11.0` |
| Fichero | `imagina-player-1.11.0.zip` |
| Tamaño | 328 KB |
| SHA-256 | `c7ec8662a04c9880fb6c475c5ea7eece68cd2fc8b2a345be8a8423e4170302b5` |
| Requiere WordPress | 6.5 o superior |
| Requiere PHP | 8.0 o superior |

Verifica la descarga con:

```sh
sha256sum imagina-player-1.11.0.zip
```

## Novedades en 1.11.0

- **Nuevo: sección Detalles de la pista.** De dónde salen el título y el artista
  cuando dejas vacíos los campos del bloque: las etiquetas del fichero, el
  nombre que tiene en tu biblioteca, o el nombre del archivo. Parte ya pasaba;
  nada se podía cambiar ni ver.
- **Nuevo:** el nombre del archivo como último recurso.
  `2024-03-11_mi-conferencia.mp3` se convierte en «Mi conferencia» — la fecha
  de delante es archivado, no título. Es lo único que tiene una dirección pegada
  de un proveedor de streaming.
- **Nuevo:** la carátula incrustada en el audio se usa como miniatura.
- **Cambiado:** los campos Título y Artista del bloque muestran en gris lo que
  el archivo les daría, en vez de quedarse vacíos junto a un reproductor que sí
  tiene título.

## Novedades en 1.10.0

- **Nuevo:** las pistas alojadas fuera (streaming, CDN) ya pueden tener onda.
  Hasta ahora no podían por ningún camino: ffmpeg lee ficheros locales, y las
  rutas de generar y guardar iban por id de la biblioteca — así que una
  dirección pegada se quedaba en barra plana y sin explicación.
- **Nuevo:** cuando el servidor remoto no autoriza a este sitio a leer sus
  ficheros —que es lo normal—, la medición pasa por este sitio. Esa puerta
  requiere permiso para añadir medios, rechaza todo lo que no sea http/https,
  rechaza direcciones privadas e internas, tiene tope de tamaño y solo responde
  con medios.

## Novedades en 1.9.2

- **Corregido:** al generar la onda desde el editor, la vista previa seguía
  mostrando la barra plana — o sea, parecía que el botón no hacía nada. La onda
  se guarda contra el archivo, no contra el bloque, así que no cambiaba ningún
  atributo que hiciera recargar la vista previa. Ya lo hace.
- **Nuevo:** el bloque de playlist revisa todas sus pistas y ofrece medir las
  que hagan falta, de una vez. Es el caso que más lo necesitaba: llegan varios
  archivos de golpe.
- **Cambiado:** el aviso pregunta directamente al servidor en vez de esperar a
  la vista previa, así que aparece en cuanto eliges el archivo.

## Novedades en 1.9.1

- **Corregido:** el editor dibujaba una onda para pistas que no tenían ninguna.
  Era sintética —un relleno para que la vista previa no pareciera una barra
  plana— y el efecto era decirte que tu onda funcionaba mientras tu sitio
  mostraba una barra plana. Ahora la vista previa muestra lo que mostrará el
  sitio, y avisa cuando falta la onda.
- **Corregido:** en un hosting sin ffmpeg, una grabación más larga que el límite
  del visitante no conseguía onda **nunca**, y nada decía por qué. Ahora puedes
  medirla en tu propio navegador —desde el bloque, o en lote en **Ondas**—: se
  descarga el archivo una vez, ahí, y el resultado queda guardado para todos los
  visitantes. Quien navega tu sitio no descarga nada de más.

## Novedades en 1.9.0

- **Nuevo: bloque de vídeo.** El reproductor maneja vídeo desde la 1.6.0, pero
  el único bloque se llamaba «Imagina Audio Player» y decía «sube un archivo de
  audio», así que quien buscaba vídeo en el insertador no encontraba nada — y
  concluía, con razón, que no había nada que encontrar. Ahora hay un bloque
  **Imagina Video Player**, con su icono y sus palabras.
- **Nuevo: sección Vídeo en los ajustes.** Forma, comportamiento del póster,
  cuánto tardan en ocultarse los controles, qué botones aparecen, cómo se ven
  los subtítulos, y si se quita el botón de descarga del navegador. Todo eso
  estaba cableado en el código.
- **Corregido:** los paneles de vídeo del bloque solo aparecían si el nombre del
  archivo parecía de vídeo. El bloque de vídeo es vídeo se llame como se llame
  el archivo.
- **Corregido:** un bloque que no fija forma ahora sigue el ajuste del sitio en
  vez de asumir panorámico.

## Novedades en 1.8.0

- **Nuevo: llamadas a la acción.** Tres tipos — un panel que pausa, una barra
  que no, y una puerta de email. Funcionan en audio igual que en vídeo: una
  puerta a dos tercios de un podcast es la misma función.
- **Nuevo:** las direcciones capturadas se guardan, se listan en **Emails** y se
  descargan en CSV. Las celdas que una hoja de cálculo ejecutaría como fórmula
  se neutralizan al exportar.
- **Nuevo: playlists**, en lista o en cuadrícula de portadas. Cada pista es un
  enlace a su propio archivo, así que pinchar una la reproduce incluso antes de
  que corra un solo script; el runtime intercepta el clic y se lo pasa al
  reproductor que ya está en la página, de modo que el volumen y la velocidad
  que eligió el oyente sobreviven al cambio.
- Cada una de estas funciones solo la descargan las páginas que la usan. Una
  página con un reproductor normal no cambia ni un byte.

### Lo que pesa, comprimido

| | |
| --- | --- |
| cualquier página con reproductor | 7 KB |
| página con vídeo | 9 KB |
| + llamadas a la acción | +1.5 KB |
| + playlist | +0.7 KB |
| página con stream, fuera de Safari | 183 KB |

## Novedades en 1.7.0

- **Nuevo: subtítulos.** WebVTT y SubRip, varios idiomas, un menú para
  cambiarlos y la elección recordada entre vídeos. Los `.srt` se convierten
  solos: el navegador solo lee WebVTT, y mandar al usuario a buscarse un
  conversor no es una función.
- **Nuevo: capítulos.** Marcas en la barra de progreso y un menú para saltar
  entre secciones. Los tiempos se escriben como 90, 1:30 o 0:01:30.
- **Nuevo: HLS**, con menú de calidad construido desde el propio stream. La
  librería de streaming solo la descargan las páginas que tienen un stream, y
  solo donde el navegador no sabe reproducirlo por su cuenta: Safari y iOS lo
  hacen de forma nativa y no pagan nada.
- **Nuevo:** en un stream protegido se firma **cada segmento**, no solo la
  lista. Firmar solo la lista no protege nada, porque las direcciones de los
  segmentos están dentro de ella en texto plano.
- **Corregido:** un stream (`.m3u8`) se dibujaba como reproductor de audio,
  porque WordPress no reconoce el tipo de archivo de una lista de reproducción.

### Lo que pesa, comprimido

| | |
| --- | --- |
| cualquier página con reproductor | 7 KB |
| página con vídeo | 9 KB |
| página con stream, fuera de Safari | 183 KB |

## Novedades en 1.6.0

- **Nuevo: vídeo.** Un reproductor construido alrededor de la imagen, no al
  lado: póster, botón de play en el centro, controles sobre el vídeo que se
  ocultan mientras se reproduce, pantalla completa, picture-in-picture, atajos
  de teclado y gestos táctiles. La caja tiene su altura definitiva antes de que
  llegue el vídeo, así que la página no salta.
- **Nuevo:** el cromo de vídeo solo lo descargan las páginas que tienen un
  vídeo. Una página con solo audio no cambia ni un byte.
- **Nuevo:** los vídeos se sirven con el botón de descarga del navegador y la
  reproducción remota desactivados, y sin menú contextual. Esto hace el archivo
  más difícil de llevarse, no imposible: nada, ni el DRM, impide grabar la
  pantalla. Lo que protege de verdad sigue siendo que el archivo está fuera de
  la carpeta pública y su enlace caduca.
- **Corregido:** el bundle no arrancaba si un plugin de optimización lo
  insertaba dentro de la página.

## Novedades en 1.5.1

- **Corregido:** el linter de JavaScript no arrancaba —se había colado una
  versión de TypeScript más nueva que la que soportan sus plugins—, así que
  nada se había revisado nunca. Ahora pasa limpio, y `npm test` ejecuta los
  linters, el comprobador de tipos y la suite de pruebas juntos.
- **Corregido:** cada interruptor de la pantalla de ajustes nombra ahora el
  control al que pertenece, en vez de depender de envolverlo.

## Novedades en 1.5.0

- **Nuevo:** comprobación de la protección. En **Protección → Comprobar que
  funciona**, el sitio escribe un archivo señuelo en la carpeta protegida, lo
  pide por HTTP real sin sesión iniciada y te dice qué respondió el servidor.
  Es la única forma de detectar el fallo habitual: nginx no lee el `.htaccess`
  que escribe el plugin, y los archivos «protegidos» estarían en abierto. Si el
  sitio no puede hacerse una petición a sí mismo, el resultado es «no se pudo
  confirmar», nunca «correcto».
- **Nuevo:** el logo se elige desde la biblioteca de medios. Sigue aceptando una
  URL pegada, porque un logo a menudo vive fuera de la biblioteca.
- **Corregido:** «no se encontró ffmpeg» se mostraba para tres problemas
  distintos con tres soluciones distintas — un hosting que prohíbe lanzar
  procesos, una ruta mal escrita, y que no esté instalado. Ahora dice cuál es.
- **Corregido:** al guardar una ruta de ffmpeg nueva, el estado que se mostraba
  era el de antes de guardar.

## Novedades en 1.4.0

- **Corregido:** el reproductor se desbordaba del contenedor en el móvil. Ahora
  el título encoge en vez de empujar, y por debajo de 30rem los controles pasan
  a una línea propia. Cada skin está medido a 320, 360, 414 y 768 píxeles con
  todos los controles activos.
- **Cambiado:** los colores de fábrica son neutros. Una instalación nueva ya no
  llega vestida con los colores del reproductor de otro cliente; los tuyos se
  ponen una vez en **Marca**.
- **Cambiado:** la pantalla de ajustes tiene identidad propia (cian de Imagina),
  separada del color que cada cliente le dé a su reproductor.

## Novedades en 1.3.1

- **Corregido:** la vista previa del bloque sacaba barras de scroll y se quedaba
  con los clics y el arrastre del editor. Ahora el marco no hace scroll y es
  inerte: todo clic y arrastre es del editor, como debe ser en algo que solo se
  mira.

## Novedades en 1.3.0

- **Corregido:** la vista previa del bloque dibujaba una copia del reproductor
  hecha a mano que se había quedado atrás: los skins tarjeta, compacto y
  pastilla salían todos como el apilado. Ahora renderiza el reproductor real,
  igual que la pantalla de ajustes.

## Novedades en 1.2.4

- **Corregido:** los colores del bloque seguían tras un menú de tres puntos y no
  se podían plegar. Ahora son un panel normal y plegable, con muestra, campo hex
  y botón de reinicio por color.
- **Corregido:** los ajustes de apariencia del bloque estaban escondidos tras un
  menú «+»; la altura de la onda es ahora un deslizador con reinicio.
- **Corregido:** el fondo del preset era una caja de texto sin selector de
  color; ahora eliges entre transparente o color, con la muestra al lado.

## Novedades en 1.2.1

- **Corregido:** la pantalla de ajustes seguía la preferencia de modo oscuro del
  navegador y se ponía oscura ella sola, mientras el resto del escritorio de
  WordPress seguía claro — con los títulos ilegibles como resultado. Ahora usa
  una sola paleta clara, como el admin que la rodea.

## Novedades en 1.2.0

- **Corregido:** la vista previa de los ajustes mostraba «no audio file
  selected» en vez de un reproductor, y con un tema de administración oscuro los
  títulos quedaban ilegibles.
- **Marca:** colores por defecto para todo el sitio, de los que arranca cada
  preset nuevo, más un logo opcional en cada reproductor.
- **CSS personalizado** desde los ajustes.
- Por preset: descripción, radio de esquina, qué pasa al terminar la pista
  (rebobinar, repetir o parar) y dónde se ancla el reproductor fijo.

## Novedades en 1.1.0

- **Pantalla de ajustes propia**, en el menú lateral: lista de presets, editor
  dividido en Controles / Comportamiento / Estilo, y **vista previa en vivo**
  que renderiza el reproductor de verdad.
- **Siete skins**: onda, onda espejada, tarjeta con portada, compacto de una
  línea, pastilla, barra de progreso y mínimo.
- La **portada y el archivo de descarga** se eligen desde la biblioteca de
  medios, ya no pidiendo una URL.
- **Generar ondas pendientes** vive ahora en esa pantalla, junto al estado de
  ffmpeg y el límite de análisis en navegador.

## Novedades en 1.0.1

- Un archivo demasiado largo para analizarse en el navegador dejaba una franja
  moviéndose de izquierda a derecha para siempre. Ya no.
- Un reproductor sin onda muestra una barra de progreso limpia en lugar de
  barras de relleno que parecían un fallo de carga.
- El control de volumen ya no se deforma con los temas que restilan los
  `<input type="range">`.

## Grabaciones largas

Para audios de más de unos minutos, la onda **tiene que generarse en el
servidor**: decodificar en el navegador expande el audio a memoria (76 minutos en
estéreo son ~1,6 GB) y no es viable.

1. Ve a **Ajustes → Imagina Player → Ondas** y mira si detecta ffmpeg.
2. Si lo detecta, pulsa **Generar ondas pendientes**.
3. Si no, pide a tu hosting que instale ffmpeg. Mientras tanto, los audios largos
   se ven como una barra de progreso normal y funcionan con normalidad.

## Qué incluye

- Reproductor de audio con forma de onda, renderizado en servidor sobre un
  `<audio>` nativo: si el JavaScript falla, el audio suena igual.
- Bloque de Gutenberg y shortcode `[imagina_player]`.
- Presets reutilizables, editables desde Ajustes.
- Medios protegidos: enlaces firmados que caducan, con soporte de `Range`,
  sesión y membresía opcionales, y entrega delegada al servidor web.

El bundle de front-end son ~5,8 KB comprimidos, sin dependencias de runtime, y
solo se carga en las páginas que llevan un reproductor.

## Actualizar

Sube el ZIP nuevo por el mismo sitio; WordPress reemplaza la versión anterior.
Los presets, las ondas cacheadas y los archivos protegidos se conservan.

## Regenerar este ZIP

Desde la rama de desarrollo:

```sh
npm install && npm run build
./bin/build-zip.sh
```
