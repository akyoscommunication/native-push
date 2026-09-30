<?php

namespace Akyos\NativePushBundle\Client;

/**
 * Just enough JWT for APNs (ES256) and Google OAuth (RS256), on top of ext-openssl.
 */
final class Jwt
{
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public static function sign(array $header, array $claims, string $pemPrivateKey): string
    {
        $key = openssl_pkey_get_private($pemPrivateKey);
        if (false === $key) {
            throw new \RuntimeException('Clé privée illisible (PEM attendu) : '.openssl_error_string());
        }

        $input = self::base64Url(json_encode($header, \JSON_THROW_ON_ERROR)).'.'.self::base64Url(json_encode($claims, \JSON_THROW_ON_ERROR));
        if (!openssl_sign($input, $signature, $key, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signature JWT impossible : '.openssl_error_string());
        }
        if ('ES256' === $header['alg']) {
            $signature = self::derToRaw($signature, 32);
        }

        return $input.'.'.self::base64Url($signature);
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * openssl returns ECDSA signatures as DER SEQUENCE { INTEGER r, INTEGER s }; JWS wants r || s, each left-padded.
     */
    public static function derToRaw(string $der, int $partLength): string
    {
        $offset = 2 + (\ord($der[1]) & 0x80 ? \ord($der[1]) & 0x7F : 0);
        $raw = '';
        for ($i = 0; $i < 2; ++$i) {
            $length = \ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $length), "\x00");
            $raw .= str_pad($int, $partLength, "\x00", \STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    /**
     * Accepts either a path to a file or the content itself (handy with %env()% secrets).
     */
    public static function readSecret(string $pathOrContent, string $label): string
    {
        if (str_starts_with(ltrim($pathOrContent), '-----BEGIN') || str_starts_with(ltrim($pathOrContent), '{')) {
            return $pathOrContent;
        }
        if (!is_file($pathOrContent) || false === ($content = @file_get_contents($pathOrContent))) {
            throw new \RuntimeException(\sprintf('%s introuvable : %s', $label, $pathOrContent));
        }

        return $content;
    }
}
