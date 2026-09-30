<?php

namespace Akyos\NativePushBundle\Command;

use Akyos\NativePushBundle\Client\ApnsClient;
use Akyos\NativePushBundle\Client\FcmClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'native-push:check', description: 'Vérifie les identifiants APNs et FCM sans envoyer de notification')]
final class CheckCommand
{
    public function __construct(private ApnsClient $apns, private FcmClient $fcm)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $failed = false;
        foreach (['iOS (APNs)' => [$this->apns, fn () => $this->apns->jwt()], 'Android (FCM)' => [$this->fcm, fn () => $this->fcm->accessToken()]] as $label => [$client, $probe]) {
            if (!$client->isConfigured()) {
                $io->writeln(\sprintf('<comment>%s</comment> : non configuré, les appareils de cette plateforme seront ignorés.', $label));
                continue;
            }
            try {
                $probe();
                $io->writeln(\sprintf('<info>%s</info> : OK — %s', $label, $client->describe()));
            } catch (\Throwable $e) {
                $failed = true;
                $io->writeln(\sprintf('<error>%s</error> : %s', $label, $e->getMessage()));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
