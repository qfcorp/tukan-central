<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Avisos por Telegram del Panel Tukán Central (mismo estilo que TuEvento).
 * config/.env: TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID y (solo pruebas) TELEGRAM_API_BASE.
 * Si Telegram no está configurado, todo sigue funcionando sin él.
 */
final class Telegram
{
    public static function configurado(): bool
    {
        return (string) getenv('TELEGRAM_BOT_TOKEN') !== '' && (string) getenv('TELEGRAM_CHAT_ID') !== '';
    }

    public function api(string $metodo, array $datos): ?array
    {
        $token = (string) getenv('TELEGRAM_BOT_TOKEN');
        if ($token === '') return null;
        $base = rtrim((string) (getenv('TELEGRAM_API_BASE') ?: 'https://api.telegram.org'), '/');
        $ch = curl_init("$base/bot$token/$metodo");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($datos, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
        ]);
        $r = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r === false || $codigo >= 400) {
            error_log("Tukan Central Telegram: $metodo fallo (HTTP $codigo)");
            return null;
        }
        return json_decode((string) $r, true);
    }

    /** Envía un texto al chat configurado. Devuelve true si Telegram lo aceptó. */
    public function enviar(string $texto): bool
    {
        if (!self::configurado()) return false;
        return (bool) $this->api('sendMessage', [
            'chat_id' => (string) getenv('TELEGRAM_CHAT_ID'),
            'text' => mb_substr($texto, 0, 3500),
            'disable_web_page_preview' => true,
        ]);
    }
}
