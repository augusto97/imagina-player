# Medios protegidos

Un archivo protegido sale de la carpeta pública de subidas y solo se sirve a
través de un enlace firmado que caduca. Esto es lo que hay que saber para
configurarlo bien.

## Lo que hace y lo que no

**Sí bloquea:** copiar la URL del MP3 y pasarla por WhatsApp, enlazarla desde
otra web (*hotlinking*), acceder al archivo sin haber iniciado sesión, y —si lo
conectas a tu plugin de cursos— escuchar sin haber comprado.

**No bloquea:** que alguien con derecho a escuchar grabe lo que oye. Ningún
sistema sin DRM puede, y el DRM real está fuera del alcance de un plugin de
WordPress. Si el audio suena en el navegador, el navegador tiene los bytes.

## Cómo proteger un archivo

1. **Ajustes → Imagina Player → Medios protegidos**: activa la protección y
   elige la duración del enlace.
2. En la biblioteca de medios, abre el archivo y marca **Proteger este archivo**.

Al marcarlo, el archivo se *mueve* físicamente a
`wp-content/uploads/imagina-protected-<hash>/`, se actualiza la ruta que
WordPress tiene registrada y a partir de ahí `wp_get_attachment_url()` devuelve
el enlace firmado — en el reproductor, en las plantillas del tema y en cualquier
otro plugin. Desmarcarlo lo devuelve a su sitio.

Firmar la URL sin mover el archivo no protegería nada: quien viera la URL real
una vez la tendría para siempre.

## Configuración del servidor

En **Apache y LiteSpeed** no hay que hacer nada: el plugin escribe un
`.htaccess` con `Require all denied` dentro de la carpeta.

En **nginx** el `.htaccess` es papel mojado. El nombre de la carpeta se deriva de
las *salts* del sitio, así que no es adivinable, pero eso es una barrera, no un
cierre. Añade esto a la configuración del sitio:

```nginx
location ^~ /wp-content/uploads/imagina-protected-XXXXXXXXXXXX/ {
    internal;
}
```

El nombre exacto de tu carpeta aparece en la pantalla de ajustes.

## Entrega: PHP frente al servidor web

Por defecto el archivo se envía desde PHP. Funciona en todas partes, pero
**mantiene ocupado un worker de PHP durante toda la reproducción**. Con pistas de
50 minutos y varios oyentes simultáneos, eso agota el pool de PHP-FPM antes que
cualquier otra cosa.

En cuanto haya tráfico real, cambia **Entrega** a `X-Accel-Redirect` (nginx) o
`X-Sendfile` (Apache/LiteSpeed): PHP valida el permiso, delega el envío al
servidor web y libera el worker de inmediato. Para nginx hace falta además:

```nginx
location /imagina-protected/ {
    internal;
    alias /ruta/absoluta/a/wp-content/uploads/imagina-protected-XXXXXXXXXXXX/;
}
```

## Caché de página: el detalle que rompe todo si se ignora

Los enlaces caducan. Una caché de página completa (WP Rocket, Varnish, Cloudflare
APO) sirve el mismo HTML durante horas, incluido un enlace que ya expiró.

Dos mecanismos lo resuelven, y funcionan sin que toques nada:

1. Los enlaces **no se firman «desde ahora»** sino desde el inicio de una ventana
   fija. Todos los visitantes de la misma ventana reciben exactamente la misma
   URL, así que la página cacheada es coherente.
2. Si aun así la reproducción falla, el reproductor **pide un enlace nuevo** a
   `/wp-json/imagina-player/v1/stream-url` y reanuda desde el segundo en el que
   estaba. Una sola vez, para no entrar en bucle.

La excepción: si activas **atar el enlace al usuario**, cada visitante necesita
una URL distinta y el HTML cacheado ya no vale para todos. En ese caso, o excluyes
esas páginas de la caché, o confías en el mecanismo 2 —que se encarga— asumiendo
un pequeño retraso en el primer play.

## Conectarlo con un plugin de cursos o membresías

Todas las comprobaciones pasan por un filtro. Devuelve `false` y no se sirve el
archivo, tenga el token que tenga:

```php
add_filter( 'imagina_player_can_stream', function ( $allowed, $attachment_id ) {
    // Ejemplo: solo los alumnos matriculados en el curso asociado.
    $course_id = get_post_meta( $attachment_id, 'curso_id', true );

    if ( ! $course_id ) {
        return $allowed;
    }

    return tu_plugin_usuario_matriculado( get_current_user_id(), $course_id );
}, 10, 2 );
```

El filtro se evalúa **en cada petición del archivo**, no al generar el enlace: si
un alumno pierde el acceso a mitad de reproducción, el siguiente fragmento que
pida su navegador ya se le deniega.

## Opciones de vinculación

| Opción | Qué consigue | Coste |
| --- | --- | --- |
| Caducidad (siempre activa) | Un enlace compartido deja de funcionar | Ninguno |
| Exigir sesión iniciada | Nadie escucha sin cuenta | Ninguno |
| Atar al usuario | El enlace de un alumno no le sirve a otro | Rompe el HTML cacheado compartido |
| Atar a la red | Añade una barrera más al reenvío | Quien pasa de wifi a datos necesita un enlace nuevo |

La vinculación por red es deliberadamente imprecisa —se descarta el último octeto
en IPv4 y la mitad de interfaz en IPv6— para no cortar la reproducción a cada
cambio de IP. Aun así, es la opción que más soporte genera; actívala solo si te
hace falta de verdad.
