<?php

namespace Akyos\NativePushBundle\Transport;

use Akyos\NativePushBundle\Client\ApnsClient;
use Akyos\NativePushBundle\Client\FcmClient;
use Akyos\NativePushBundle\Client\PushResult;
use Akyos\NativePushBundle\Model\Platform;
use Akyos\NativePushBundle\PushDeviceManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Notifier\Exception\RuntimeException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\AbstractTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Sends a PushMessage to every device of a subject, routing each one to APNs or FCM.
 */
final class NativePushTransport extends AbstractTransport
{
    public function __construct(
        private ApnsClient $apns,
        private FcmClient $fcm,
        private PushDeviceManager $devices,
        private LoggerInterface $logger = new NullLogger(),
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        parent::__construct(null, $dispatcher);
    }

    public function __toString(): string
    {
        return 'native-push://default';
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof PushMessage && $message->getOptions() instanceof NativePushOptions;
    }

    /**
     * Unconfigured platforms and subjects without devices are skipped, never fatal: a business action
     * must not break because iOS is not set up yet. Only "every attempted send failed" throws.
     */
    protected function doSend(MessageInterface $message): SentMessage
    {
        if (!$this->supports($message)) {
            throw new UnsupportedMessageTypeException(__CLASS__, PushMessage::class.' avec '.NativePushOptions::class, $message);
        }
        /** @var PushMessage $message */
        /** @var NativePushOptions $options */
        $options = $message->getOptions();

        $pending = [];
        foreach ($this->targets($options) as [$token, $platform]) {
            $client = Platform::Ios === $platform ? $this->apns : $this->fcm;
            if (!$client->isConfigured()) {
                $this->logger->warning('Push ignoré : {platform} non configuré dans akyos_native_push.', ['platform' => $platform->value]);
                continue;
            }
            try {
                // Symfony HttpClient responses are lazy: every request is in flight before the first result is read.
                $pending[] = [$token, $client, $client->send($token, $message->getSubject(), $message->getContent(), $options)];
            } catch (\RuntimeException|\LogicException $e) {
                $pending[] = [$token, $client, PushResult::error($e->getMessage())];
            }
        }

        $ids = $errors = $deadTokens = [];
        foreach ($pending as [$token, $client, $response]) {
            $result = $response instanceof PushResult ? $response : $client->result($response);
            if ($result->ok) {
                $ids[] = $result->id;
                continue;
            }
            $errors[] = $result->error;
            if ($result->invalidToken) {
                $deadTokens[] = $token;
            }
            $this->logger->error('Push refusé pour {token} : {error}', ['token' => substr($token, 0, 12).'…', 'error' => $result->error]);
        }
        $this->devices->deleteByTokens($deadTokens);

        if ([] === $ids && [] !== $errors) {
            throw new RuntimeException('Aucune notification push n’a été acceptée : '.implode(' ; ', array_unique($errors)));
        }

        $sent = new SentMessage($message, (string) $this);
        if ([] !== $ids) {
            $sent->setMessageId(implode(',', array_filter($ids)) ?: 'sent');
        }

        return $sent;
    }

    /**
     * @return list<array{string, Platform}>
     */
    private function targets(NativePushOptions $options): array
    {
        if (null === $options->getSubjectClass()) {
            return $options->getTokens();
        }

        return array_map(
            static fn ($device): array => [$device->getToken(), $device->getPlatform()],
            $this->devices->devicesOf($options->getSubjectClass(), $options->getSubjectId()),
        );
    }
}
