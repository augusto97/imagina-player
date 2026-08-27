# Imagina Player — descargas

Esta rama contiene únicamente el plugin empaquetado y listo para instalar. El
código fuente está en la rama de desarrollo.

## Instalar

1. Descarga `imagina-player-1.0.0.zip`.
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin**.
3. Sube el ZIP y actívalo.
4. Configura los presets en **Ajustes → Imagina Player**.

## Versión actual

| | |
| --- | --- |
| Versión | `1.0.0` |
| Fichero | `imagina-player-1.0.0.zip` |
| Tamaño | 76 KB |
| SHA-256 | `792def1af4d013a2aaeff8743f51a384997627be8f430efd898489cc650263ab` |
| Requiere WordPress | 6.5 o superior |
| Requiere PHP | 8.0 o superior |

Verifica la descarga con:

```sh
sha256sum imagina-player-1.0.0.zip
```

## Qué incluye 1.0.0

- Reproductor de audio con forma de onda, renderizado en servidor sobre un
  `<audio>` nativo: si el JavaScript falla, el audio suena igual.
- Bloque de Gutenberg y shortcode `[imagina_player]`.
- Presets reutilizables, editables desde Ajustes.
- Ondas generadas con ffmpeg fuera de la petición, o por el navegador del primer
  visitante, y cacheadas como un byte por barra.
- Medios protegidos: enlaces firmados que caducan, con soporte de `Range`,
  sesión y membresía opcionales, y entrega delegada al servidor web.

El bundle de front-end son ~5 KB comprimidos, sin dependencias de runtime, y solo
se carga en las páginas que llevan un reproductor.

## Primera instalación

Este plugin no se ha ejecutado todavía dentro de un WordPress real: está
verificado con 172 comprobaciones automatizadas, incluidas peticiones HTTP reales
contra el servidor de streaming, pero eso no sustituye a una instalación.
**Pruébalo primero en staging.**

## Actualizar

Sube el ZIP nuevo por el mismo sitio; WordPress reemplaza la versión anterior.
Los presets, las ondas cacheadas y los archivos protegidos se conservan.

## Regenerar este ZIP

Desde la rama de desarrollo:

```sh
npm install && npm run build
./bin/build-zip.sh
```
