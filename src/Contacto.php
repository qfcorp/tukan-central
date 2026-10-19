<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Datos personales (teléfonos y correos): se guardan cifrados, se muestran completos solo al rol dueño
 * (PROTOCOLO_V2 §9.3 y §9.7) y nunca van completos a Telegram ni a los registros.
 * También arma los enlaces y mensajes de WhatsApp.
 */
final class Contacto
{
    public const PLANTILLAS = [
        'wa_recordar_prueba' => 'Hola {nombre}, le saluda Tukán Software. ¿Cómo le va con la demo de {negocio} en {programa}? Su demo termina el {vence}. Si desea seguir usándolo, con gusto le ayudamos a activar su plan.',
        'wa_recordar_renovacion' => 'Hola {nombre}, le saluda Tukán Software. Le recordamos que el plan de {negocio} en {programa} vence el {vence}. El valor de la renovación es {monto}. ¿Le ayudamos a renovarlo?',
        'wa_vencido' => 'Hola {nombre}, le saluda Tukán Software. El plan de {negocio} en {programa} venció el {vence}. Para seguir usándolo sin perder sus datos, renueve su plan. ¿Le ayudamos?',
        'wa_bienvenida' => 'Hola {nombre}, le saluda Tukán Software. ¡Bienvenido a {programa}! {negocio} ya está lista para usarse. Cualquier duda, escríbanos por aquí.',
    ];

    public const NOMBRES_PLANTILLAS = [
        'wa_recordar_prueba' => 'Demo por terminar',
        'wa_recordar_renovacion' => 'Plan por vencer',
        'wa_vencido' => 'Plan vencido',
        'wa_bienvenida' => 'Bienvenida',
    ];

    /** Para la pantalla del rol soporte: 0991234567 -> 09•••••67 */
    public static function telefono(?string $t): string
    {
        $d = preg_replace('/\D+/', '', (string) $t) ?? '';
        if ($d === '') return '';
        if (strlen($d) <= 4) return str_repeat('•', strlen($d));
        return substr($d, 0, 2) . '•••••' . substr($d, -2);
    }

    /** Para Telegram: 0991234567 -> 099•••4567 */
    public static function telefonoAviso(?string $t): string
    {
        $d = preg_replace('/\D+/', '', (string) $t) ?? '';
        if (str_starts_with($d, '593') && strlen($d) >= 11) $d = '0' . substr($d, 3);
        if (strlen($d) < 8) return $d === '' ? '' : str_repeat('•', strlen($d));
        return substr($d, 0, 3) . '•••' . substr($d, -4);
    }

    /** juan@ejemplo.com -> j•••@ejemplo.com */
    public static function email(?string $e): string
    {
        $e = (string) $e;
        if (!str_contains($e, '@')) return $e === '' ? '' : '•••';
        [$u, $d] = explode('@', $e, 2);
        return mb_substr($u, 0, 1) . '•••@' . $d;
    }

    /** Oculta correos y teléfonos dentro de un texto libre (detalles de solicitudes para el rol soporte). */
    public static function enmascararTexto(string $t): string
    {
        $t = (string) preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', fn($m) => self::email($m[0]), $t);
        return (string) preg_replace_callback('/\+?\d[\d \-]{7,}\d/', fn($m) => self::telefono($m[0]), $t);
    }

    /** Recorre un arreglo y enmascara los textos. */
    public static function enmascararTodo(mixed $v): mixed
    {
        if (is_string($v)) return self::enmascararTexto($v);
        if (is_array($v)) return array_map([self::class, 'enmascararTodo'], $v);
        return $v;
    }

    /** Número para wa.me: solo dígitos con código de país (09... de Ecuador -> 5939...). */
    public static function numeroWhatsapp(?string $t): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $t) ?? '';
        if (preg_match('/^0\d{9}$/', $d)) $d = '593' . substr($d, 1);
        return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
    }

    public static function urlWhatsapp(string $numero, string $texto = ''): string
    {
        return 'https://wa.me/' . preg_replace('/\D+/', '', $numero) . ($texto !== '' ? '?text=' . rawurlencode($texto) : '');
    }

    /** ¿Es un enlace de WhatsApp que se puede abrir tal cual? */
    public static function esUrlWhatsapp(?string $u): bool
    {
        return is_string($u) && strlen($u) < 4000 && (bool) preg_match('#^https://(wa\.me|api\.whatsapp\.com)/[0-9A-Za-z/?=&%._~+-]*$#', $u);
    }

    /** Reemplaza {nombre} {negocio} {programa} {vence} {monto}. */
    public static function plantilla(string $texto, array $v): string
    {
        $r = [];
        foreach (['nombre', 'negocio', 'programa', 'vence', 'monto'] as $k) $r['{' . $k . '}'] = (string) ($v[$k] ?? '');
        return trim(preg_replace('/ {2,}/', ' ', strtr($texto, $r)) ?? '');
    }

    /** Qué plantilla corresponde al estado del cliente (null = no hay a quién recordar). */
    public static function plantillaPara(array $c): ?string
    {
        return match ($c['estado'] ?? '') {
            'prueba' => 'wa_recordar_prueba',
            'por_vencer', 'activo' => 'wa_recordar_renovacion',
            'vencido' => ((float) ($c['total_pagado'] ?? 0) > 0 ? 'wa_vencido' : 'wa_recordar_prueba'),
            default => null,
        };
    }
}
