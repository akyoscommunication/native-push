<?php

namespace Akyos\NativePushBundle\Client;

use Akyos\NativePushBundle\Transport\NativePushOptions;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * FCM HTTP v1 API authenticated with a Firebase service account (OAuth2 JWT bearer grant).
 */
final class FcmClient
{
    private ?array $serviceAccount = null;

    /**
     * @param array{service_account: string}|null $config
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
        if (null === $this->config) {
            return 'non configuré';
        }
        $account = $this->serviceAccount();

        return \sprintf('projet %s, compte %s', $account['project_id'], $account['client_email']);
    }

    /**
     * Google access tokens live 1 h: cached 55 min.
     */
    public function accessToken(): string
    {
        $account = $this->serviceAccount();

        return $this->cache->get($this->cacheKey(), function (ItemInterface $item) use ($account): string {
            $item->expiresAfter(3300);
            $now = time();
            $tokenUri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $assertion = Jwt::sign(['alg' => 'RS256', 'typ' => 'JWT'], [
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $account['private_key']);

            $response = $this->httpClient->request('POST', $tokenUri, ['body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]]);
            $data = $response->toArray(false);

            return $data['access_token'] ?? throw new \RuntimeException('Google OAuth a refusé le compte de service : '.($data['error_description'] ?? $data['error'] ?? 'HTTP '.$response->getStatusCode()));
        });
    }

    public function send(string $token, string $title, string $body, NativePushOptions $options): ResponseInterface
    {
        return $this->httpClient->request('POST', \sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->serviceAccount()['project_id']), [
            'auth_bearer' => $this->accessToken(),
            'json' => self::payload($token, $title, $body, $options),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(string $token, string $title, string $body, NativePushOptions $options): array
    {
        $data = array_map(static fn ($v): string => \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $options->getData() + array_filter(['url' => $options->getUrl()]));
        $notification = array_filter(['sound' => $options->getSound(), 'notification_count' => $options->getBadge()], static fn ($v): bool => null !== $v);

        return ['message' => array_filter([
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $data ?: null,
            'android' => ['priority' => 'high'] + ($notification ? ['notification' => $notification] : []),
        ])];
    }

    public function result(ResponseInterface $response): PushResult
    {
        try {
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            return PushResult::error('FCM injoignable : '.$e->getMessage());
        }
        if (200 === $status) {
            return PushResult::ok($body['name'] ?? null);
        }

        $error = $body['error'] ?? [];
        $codes = array_column($error['details'] ?? [], 'errorCode');
        $message = \sprintf('FCM %s : %s', $error['status'] ?? 'HTTP '.$status, $error['message'] ?? '');
        if (401 === $status) {
            $this->cache->delete($this->cacheKey());
        }

        return \in_array('UNREGISTERED', $codes, true)
            || ('INVALID_ARGUMENT' === ($error['status'] ?? null) && str_contains($error['message'] ?? '', 'registration token'))
            ? PushResult::invalidToken($message)
            : PushResult::error($message);
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri?: string}
     */
    private function serviceAccount(): array
    {
        if (null !== $this->serviceAccount) {
            return $this->serviceAccount;
        }
        $config = $this->config ?? throw new \LogicException('FCM non configuré : ajoutez la section akyos_native_push.fcm.');
        $account = json_decode(Jwt::readSecret($config['service_account'], 'Compte de service Firebase'), true);
        if (!\is_array($account) || !isset($account['project_id'], $account['client_email'], $account['private_key'])) {
            throw new \RuntimeException('Compte de service Firebase invalide : project_id, client_email et private_key sont attendus.');
        }

        return $this->serviceAccount = $account;
    }

    private function cacheKey(): string
    {
        return 'akyos_native_push.fcm_token.'.md5($this->serviceAccount()['client_email']);
    }
}
