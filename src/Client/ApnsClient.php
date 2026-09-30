<?php

namespace Akyos\NativePushBundle\Client;

use Akyos\NativePushBundle\Transport\NativePushOptions;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * APNs HTTP/2 provider API with token-based (.p8) authentication.
 */
final class ApnsClient
{
    /**
     * @param array{team_id: string, key_id: string, private_key: string, topic: string, sandbox: bool}|null $config
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private ?array $config = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->config;
    }

    public function describe(): string
    {
        return null === $this->config ? 'non configuré' : \sprintf('%s, topic %s, team %s, clé %s', $this->config['sandbox'] ? 'sandbox' : 'production', $this->config['topic'], $this->config['team_id'], $this->config['key_id']);
    }

    /**
     * Apple rejects tokens older than 1 h and refreshed more than once per 20 min: 50 min sits in between.
     */
    public function jwt(): string
    {
        $config = $this->config();

        return $this->cache->get($this->cacheKey(), static function (ItemInterface $item) use ($config): string {
            $item->expiresAfter(3000);

            return Jwt::sign(
                ['alg' => 'ES256', 'kid' => $config['key_id']],
                ['iss' => $config['team_id'], 'iat' => time()],
                Jwt::readSecret($config['private_key'], 'Clé APNs (.p8)'),
            );
        });
    }

    public function send(string $token, string $title, string $body, NativePushOptions $options): ResponseInterface
    {
        $config = $this->config();

        return $this->httpClient->request('POST', \sprintf('https://%s/3/device/%s', $config['sandbox'] ? 'api.sandbox.push.apple.com' : 'api.push.apple.com', $token), [
            'http_version' => '2.0',
            'headers' => [
                'authorization' => 'bearer '.$this->jwt(),
                'apns-topic' => $config['topic'],
                'apns-push-type' => 'alert',
                'apns-priority' => '10',
            ],
            'json' => self::payload($title, $body, $options),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(string $title, string $body, NativePushOptions $options): array
    {
        $aps = ['alert' => ['title' => $title, 'body' => $body]];
        if (null !== $options->getSound()) {
            $aps['sound'] = $options->getSound();
        }
        if (null !== $options->getBadge()) {
            $aps['badge'] = $options->getBadge();
        }

        return ['aps' => $aps] + $options->getData() + array_filter(['url' => $options->getUrl()]);
    }

    public function result(ResponseInterface $response): PushResult
    {
        try {
            $status = $response->getStatusCode();
            if (200 === $status) {
                return PushResult::ok($response->getHeaders(false)['apns-id'][0] ?? null);
            }
            $reason = $response->toArray(false)['reason'] ?? 'HTTP '.$status;
        } catch (ExceptionInterface $e) {
            return PushResult::error('APNs injoignable : '.$e->getMessage());
        }

        if ('ExpiredProviderToken' === $reason || 'InvalidProviderToken' === $reason) {
            $this->cache->delete($this->cacheKey());
        }

        // Only 410 means "gone for good". BadDeviceToken also happens on a sandbox/production mismatch, so deleting there would wipe valid devices.
        return match (true) {
            410 === $status => PushResult::invalidToken('APNs '.$reason),
            'BadDeviceToken' === $reason => PushResult::error('APNs BadDeviceToken : token invalide ou mauvais environnement (vérifiez akyos_native_push.apns.sandbox)'),
            default => PushResult::error('APNs '.$reason),
        };
    }

    /**
     * @return array{team_id: string, key_id: string, private_key: string, topic: string, sandbox: bool}
     */
    private function config(): array
    {
        return $this->config ?? throw new \LogicException('APNs non configuré : ajoutez la section akyos_native_push.apns.');
    }

    private function cacheKey(): string
    {
        return 'akyos_native_push.apns_jwt.'.$this->config()['key_id'];
    }
}
