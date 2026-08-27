# Imagina Player — descargas

Esta rama contiene únicamente el plugin empaquetado y listo para instalar. El
código fuente está en la rama de desarrollo.

## Instalar

1. Descarga `imagina-player-1.5.1.zip`.
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin**.
3. Sube el ZIP y actívalo.
4. Configura los presets en **Imagina Player** (menú lateral), o desde el enlace
   **Ajustes** de la fila del plugin en **Plugins → Plugins instalados**.

## Versión actual

| | |
| --- | --- |
| Versión | `1.5.1` |
| Fichero | `imagina-player-1.5.1.zip` |
| Tamaño | 104 KB |
| SHA-256 | `47e5f75f4458600dfde39fa8f0afc89d79192effd058f578ca688863d146c8a8` |
| Requiere WordPress | 6.5 o superior |
| Requiere PHP | 8.0 o superior |

Verifica la descarga con:

```sh
sha256sum imagina-player-1.5.1.zip
```

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
