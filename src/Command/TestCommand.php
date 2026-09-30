<?php

namespace Akyos\NativePushBundle\Command;

use Akyos\NativePushBundle\Client\ApnsClient;
use Akyos\NativePushBundle\Client\FcmClient;
use Akyos\NativePushBundle\Model\Platform;
use Akyos\NativePushBundle\Transport\NativePushOptions;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'native-push:test', description: 'Envoie une notification directement à un token et affiche la réponse brute d’APNs ou de FCM')]
final class TestCommand
{
    public function __construct(private ApnsClient $apns, private FcmClient $fcm)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Token de l’appareil (APNs ou FCM)')] string $token,
        #[Option('ios ou android (déduit du format du token si absent)')] ?string $platform = null,
        #[Option('Titre')] string $title = 'Test native-push',
        #[Option('Texte')] string $body = 'Si vous lisez ceci, tout fonctionne.',
        #[Option('Page ouverte au toucher (chemin ou URL)')] ?string $url = null,
    ): int {
        $platform = null !== $platform ? Platform::tryFrom($platform) : (Platform::Ios->isValidToken($token) ? Platform::Ios : Platform::Android);
        if (null === $platform || !$platform->isValidToken($token)) {
            $io->error('Plateforme inconnue ou token au mauvais format.');

            return Command::INVALID;
        }
        $client = Platform::Ios === $platform ? $this->apns : $this->fcm;
        if (!$client->isConfigured()) {
            $io->error(\sprintf('%s non configuré : ajoutez la section akyos_native_push.%s.', $platform->value, Platform::Ios === $platform ? 'apns' : 'fcm'));

            return Command::FAILURE;
        }

        try {
            $response = $client->send($token, $title, $body, NativePushOptions::tokens([$token], $platform)->url($url));
            $io->writeln(\sprintf('<comment>%s</comment> HTTP %d', $platform->value, $response->getStatusCode()));
            $io->writeln($response->getContent(false) ?: '(corps vide)');
            $result = $client->result($response);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$result->ok) {
            $io->error($result->error.($result->invalidToken ? ' — ce token est définitivement invalide.' : ''));

            return Command::FAILURE;
        }
        $io->success('Accepté par '.(Platform::Ios === $platform ? 'APNs' : 'FCM').($result->id ? ' ('.$result->id.')' : '').'.');

        return Command::SUCCESS;
    }
}
