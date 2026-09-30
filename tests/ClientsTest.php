<?php

namespace Akyos\NativePushBundle\Tests;

use Akyos\NativePushBundle\Client\ApnsClient;
use Akyos\NativePushBundle\Client\FcmClient;
use Akyos\NativePushBundle\Client\Jwt;
use Akyos\NativePushBundle\Model\Platform;
use Akyos\NativePushBundle\Transport\NativePushOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientsTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri: string}
     */
    public static function serviceAccount(): array
    {
        static $key;
        $key ??= openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);

        return ['project_id' => 'demo-project', 'client_email' => 'push@demo-project.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token'];
    }

    public function testApnsSignsAValidEs256JwtAndBuildsThePayload(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$url, $options];

            return new MockResponse('', ['http_code' => 200, 'response_headers' => ['apns-id' => 'ABC-123']]);
        });
        $apns = new ApnsClient($http, new ArrayAdapter(), ['team_id' => 'TEAM123456', 'key_id' => 'KEY1234567', 'private_key' => $pem, 'topic' => 'com.akyos.demo', 'sandbox' => true]);

        $result = $apns->result($apns->send(self::TOKEN, 'Titre', 'Texte', NativePushOptions::tokens([self::TOKEN], Platform::Ios)->url('/orders/42')->badge(3)));
        self::assertTrue($result->ok);
        self::assertSame('ABC-123', $result->id);

        [$url, $options] = $requests[0];
        self::assertSame('https://api.sandbox.push.apple.com/3/device/'.self::TOKEN, $url);
        self::assertSame(['aps' => ['alert' => ['title' => 'Titre', 'body' => 'Texte'], 'sound' => 'default', 'badge' => 3], 'url' => '/orders/42'], json_decode($options['body'], true));

        [$header, $claims, $signature] = explode('.', $apns->jwt());
        self::assertSame(['alg' => 'ES256', 'kid' => 'KEY1234567'], json_decode(base64_decode(strtr($header, '-_', '+/')), true));
        self::assertSame('TEAM123456', json_decode(base64_decode(strtr($claims, '-_', '+/')), true)['iss']);
        $raw = base64_decode(strtr($signature, '-_', '+/'));
        self::assertSame(64, \strlen($raw));
        self::assertSame(1, openssl_verify($header.'.'.$claims, self::rawToDer($raw), openssl_pkey_get_details($key)['key'], \OPENSSL_ALGO_SHA256), 'R||S must round-trip to a valid DER signature');
    }

    public function testApnsOnlyTreats410AsDeadToken(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $apns = new ApnsClient(new MockHttpClient([
            new JsonMockResponse(['reason' => 'Unregistered'], ['http_code' => 410]),
            new JsonMockResponse(['reason' => 'BadDeviceToken'], ['http_code' => 400]),
        ]), new ArrayAdapter(), ['team_id' => 'TEAM123456', 'key_id' => 'KEY1234567', 'private_key' => $pem, 'topic' => 't', 'sandbox' => false]);
        $options = NativePushOptions::tokens([self::TOKEN], Platform::Ios);

        self::assertTrue($apns->result($apns->send(self::TOKEN, 'a', 'b', $options))->invalidToken);
        $badToken = $apns->result($apns->send(self::TOKEN, 'a', 'b', $options));
        self::assertFalse($badToken->ok);
        self::assertFalse($badToken->invalidToken, 'BadDeviceToken can be a sandbox mismatch: never delete on it');
    }

    public function testFcmExchangesServiceAccountThenSendsStringData(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$url, $options];

            return str_contains($url, 'oauth2')
                ? new JsonMockResponse(['access_token' => 'ya29.test', 'expires_in' => 3599])
                : new JsonMockResponse(['name' => 'projects/demo-project/messages/1']);
        });
        $fcm = new FcmClient($http, new ArrayAdapter(), ['service_account' => json_encode(self::serviceAccount())]);
        $options = NativePushOptions::tokens(['tok'], Platform::Android)->url('/orders/42')->data(['id' => 42, 'urgent' => true]);

        self::assertSame('projects/demo-project/messages/1', $fcm->result($fcm->send('fcm-token', 'Titre', 'Texte', $options))->id);
        $fcm->send('fcm-token', 'Titre', 'Texte', $options);
        self::assertCount(3, $requests, 'OAuth token is cached between sends');

        parse_str($requests[0][1]['body'], $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        [$h, $c, $s] = explode('.', $form['assertion']);
        self::assertSame(1, openssl_verify($h.'.'.$c, base64_decode(strtr($s, '-_', '+/')), openssl_pkey_get_details(openssl_pkey_get_private(self::serviceAccount()['private_key']))['key'], \OPENSSL_ALGO_SHA256));

        [$url, $send] = $requests[1];
        self::assertSame('https://fcm.googleapis.com/v1/projects/demo-project/messages:send', $url);
        self::assertContains('Authorization: Bearer ya29.test', $send['headers']);
        $message = json_decode($send['body'], true)['message'];
        self::assertSame(['id' => '42', 'urgent' => 'true', 'url' => '/orders/42'], $message['data']);
        self::assertSame(['title' => 'Titre', 'body' => 'Texte'], $message['notification']);
    }

    public function testFcmUnregisteredIsDeadButQuotaIsNot(): void
    {
        $fcm = new FcmClient(new MockHttpClient([
            new JsonMockResponse(['access_token' => 'ya29.test']),
            new JsonMockResponse(['error' => ['status' => 'NOT_FOUND', 'message' => 'x', 'details' => [['errorCode' => 'UNREGISTERED']]]], ['http_code' => 404]),
            new JsonMockResponse(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota']], ['http_code' => 429]),
        ]), new ArrayAdapter(), ['service_account' => json_encode(self::serviceAccount())]);
        $options = NativePushOptions::tokens(['tok'], Platform::Android);

        self::assertTrue($fcm->result($fcm->send('tok', 'a', 'b', $options))->invalidToken);
        $quota = $fcm->result($fcm->send('tok', 'a', 'b', $options));
        self::assertFalse($quota->ok);
        self::assertFalse($quota->invalidToken);
    }

    public function testDerToRawPadsShortIntegers(): void
    {
        $der = "\x30\x08\x02\x02\x00\x81\x02\x02\x01\x02";
        self::assertSame(str_repeat("\x00", 31)."\x81".str_repeat("\x00", 30)."\x01\x02", Jwt::derToRaw($der, 32));
    }

    private static function rawToDer(string $raw): string
    {
        $int = static function (string $part): string {
            $part = ltrim($part, "\x00");
            if (\ord($part[0]) & 0x80) {
                $part = "\x00".$part;
            }

            return "\x02".\chr(\strlen($part)).$part;
        };
        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

        return "\x30".\chr(\strlen($body)).$body;
    }
}
