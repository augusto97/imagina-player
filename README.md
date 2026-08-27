# Imagina Player — descargas

Esta rama contiene únicamente el plugin empaquetado y listo para instalar. El
código fuente está en la rama de desarrollo.

## Instalar

1. Descarga `imagina-player-1.0.1.zip`.
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin**.
3. Sube el ZIP y actívalo.
4. Configura los presets en **Ajustes → Imagina Player**.

## Versión actual

| | |
| --- | --- |
| Versión | `1.0.1` |
| Fichero | `imagina-player-1.0.1.zip` |
| Tamaño | 76 KB |
| SHA-256 | `df2a7c7cbb6c5c6b3c812158c60ea647ad8b168a5c35fe133b8302d0c92fee2f` |
| Requiere WordPress | 6.5 o superior |
| Requiere PHP | 8.0 o superior |

Verifica la descarga con:

```sh
sha256sum imagina-player-1.0.1.zip
```

## Novedades en 1.0.1

Correcciones de la primera instalación real:

- Un archivo demasiado largo para analizarse en el navegador dejaba una franja
  moviéndose de izquierda a derecha para siempre. Ahora se comprueba el tamaño
  antes de descargar nada, se abandona a los 30 segundos, y la animación se
  detiene igualmente.
- Un reproductor sin onda dibujaba barras de relleno que parecían un fallo de
  carga. Ahora muestra una barra de progreso limpia.
- Nuevo botón **Generar ondas pendientes** en Ajustes, para las grabaciones
  largas, sin depender de WP-Cron.
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
