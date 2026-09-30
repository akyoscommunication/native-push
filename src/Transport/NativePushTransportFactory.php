<?php

namespace Akyos\NativePushBundle\Transport;

use Akyos\NativePushBundle\Client\ApnsClient;
use Akyos\NativePushBundle\Client\FcmClient;
use Akyos\NativePushBundle\PushDeviceManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport\AbstractTransportFactory;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * DSN: native-push://default. Credentials live in the akyos_native_push config (they are files, not DSN material).
 */
final class NativePushTransportFactory extends AbstractTransportFactory
{
    public function __construct(
        private ApnsClient $apns,
        private FcmClient $fcm,
        private PushDeviceManager $devices,
        private LoggerInterface $logger,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        parent::__construct($dispatcher);
    }

    public function create(Dsn $dsn): NativePushTransport
    {
        if ('native-push' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'native-push', $this->getSupportedSchemes());
        }

        return new NativePushTransport($this->apns, $this->fcm, $this->devices, $this->logger, $this->dispatcher);
    }

    protected function getSupportedSchemes(): array
    {
        return ['native-push'];
    }
}
