<?php

namespace Akyos\NativePushBundle\Controller;

use Akyos\NativePushBundle\Model\Platform;
use Akyos\NativePushBundle\PushDeviceManager;
use Akyos\NativePushBundle\SubjectResolver\SubjectResolverInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST / DELETE /native-push/devices with {"token": "...", "platform"?: "ios|android"}.
 */
final class DeviceController
{
    public function __construct(
        private PushDeviceManager $devices,
        private SubjectResolverInterface $subjectResolver,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        if (null !== $refused = $this->refuseCrossSite($request)) {
            return $refused;
        }
        [$token, $platform] = $this->readPayload($request);
        if (null === $platform || !$platform->isValidToken($token)) {
            return new JsonResponse(['error' => 'Token ou plateforme invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $subject = $this->subjectResolver->resolve($request);
        if (null === $subject) {
            return new JsonResponse(['error' => 'Aucun sujet push pour cette requête (utilisateur non connecté ?).'], Response::HTTP_UNAUTHORIZED);
        }

        $device = $this->devices->register($subject, $token, $platform);

        return new JsonResponse([
            'platform' => $device->getPlatform()->value,
            'subject' => $device->getSubjectClass().'#'.$device->getSubjectId(),
        ], Response::HTTP_CREATED);
    }

    public function unregister(Request $request): Response
    {
        if (null !== $refused = $this->refuseCrossSite($request)) {
            return $refused;
        }
        [$token] = $this->readPayload($request);
        // ponytail: anyone holding the token may delete it; tokens are secret to the device, so this is not an escalation.
        $this->devices->unregister($token);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * JSON bodies cannot be sent cross-origin without a CORS preflight, and the Origin check closes the rest.
     */
    private function refuseCrossSite(Request $request): ?JsonResponse
    {
        $origin = $request->headers->get('Origin');
        if ('json' !== $request->getContentTypeFormat() || (null !== $origin && $origin !== $request->getSchemeAndHttpHost())) {
            return new JsonResponse(['error' => 'Requête JSON same-origin attendue.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @return array{string, ?Platform}
     */
    private function readPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        $token = \is_array($payload) && \is_string($payload['token'] ?? null) ? trim($payload['token']) : '';
        $platform = \is_array($payload) && \is_string($payload['platform'] ?? null)
            ? Platform::tryFrom($payload['platform'])
            : Platform::fromUserAgent((string) $request->headers->get('User-Agent'));

        return [$token, $platform];
    }
}
