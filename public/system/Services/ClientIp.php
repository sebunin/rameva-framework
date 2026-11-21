<?php
/** Resolve client IP, trusting forwarding headers only from configured proxies. */

declare(strict_types=1);

namespace System\Services;

if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

final class ClientIp
{
    /** @var string|null Кэшированный IP на время запроса (G2) */
    private static ?string $cachedIp = null;

    public static function get(): string
    {
        if (self::$cachedIp !== null) {
            return self::$cachedIp;
        }

        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            self::$cachedIp = '127.0.0.1';
            return self::$cachedIp;
        }

        // G1: trusted_proxies из app/Config/Security.php (поддержка CIDR)
        $secFile = APPPATH . '/Config/Security.php';
        $secConfig = is_file($secFile) ? (require $secFile) : [];
        $trusted = (array)($secConfig['trusted_proxies'] ?? []);

        // Если REMOTE_ADDR не доверенный прокси — он и есть клиент.
        if (!self::isTrustedIp($remote, $trusted)) {
            self::$cachedIp = $remote;
            return self::$cachedIp;
        }

        // REMOTE_ADDR — доверенный прокси: читаем X-Forwarded-For справа налево,
        // пропуская адреса из trusted_proxies. Первый непропущенный — клиент.
        $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            $candidates = array_map('trim', explode(',', $xff));
            foreach (array_reverse($candidates) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    self::$cachedIp = $remote;
                    return self::$cachedIp;
                }
                if (self::isTrustedIp($candidate, $trusted)) {
                    continue;
                }
                self::$cachedIp = $candidate;
                return self::$cachedIp;
            }
            self::$cachedIp = $remote;
            return self::$cachedIp;
        }

        $cfip = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cfip !== '' && filter_var($cfip, FILTER_VALIDATE_IP) !== false && !self::isTrustedIp($cfip, $trusted)) {
            self::$cachedIp = $cfip;
            return self::$cachedIp;
        }

        self::$cachedIp = $remote;
        return self::$cachedIp;
    }

    /**
     * G1: Проверка вхождения IP в список доверенных с поддержкой CIDR.
     *
     * @param string $ip IP-адрес
     * @param array $trusted Список trusted_proxies (IP или CIDR)
     * @return bool
     */
    private static function isTrustedIp(string $ip, array $trusted): bool
    {
        foreach ($trusted as $proxy) {
            $proxy = trim((string)$proxy);
            if ($proxy === '') {
                continue;
            }

            if (str_contains($proxy, '/')) {
                // CIDR-сеть
                [$net, $mask] = explode('/', $proxy, 2) + [null, null];
                if ($net === null || $mask === null) {
                    continue;
                }
                if (ipInCidr($ip, $net, (int)$mask)) {
                    return true;
                }
            } elseif ($proxy === $ip) {
                return true;
            }
        }

        return false;
    }
}

/**
 * G1: Проверка belongs-to-CIDR.
 *
 * @param string $ip IP-адрес
 * @param string $cidr Сетевой адрес
 * @param int $mask Маска (0-32 для IPv4, 0-128 для IPv6)
 * @return bool
 */
function ipInCidr(string $ip, string $cidr, int $mask): bool
{
    if (strpos($cidr, ':') !== false || strpos($ip, ':') !== false) {
        // IPv6
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($cidr);
        if ($ipBin === false || $netBin === false) {
            return false;
        }
        $ipBin = str_pad(base_convert(bin2hex($ipBin), 16, 2), 128, '0', STR_PAD_LEFT);
        $netBin = str_pad(base_convert(bin2hex($netBin), 16, 2), 128, '0', STR_PAD_LEFT);
    } else {
        // IPv4
        $ipLong = ip2long($ip);
        $netLong = ip2long($cidr);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        $ipBin = str_pad(decbin($ipLong), 32, '0', STR_PAD_LEFT);
        $netBin = str_pad(decbin($netLong), 32, '0', STR_PAD_LEFT);
    }

    return strncmp($ipBin, $netBin, $mask) === 0;
}