<?php

namespace Akyos\NativePushBundle\Command;

use Akyos\NativePushBundle\Model\PushSubjectInterface;
use Akyos\NativePushBundle\Transport\NativePushOptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

#[AsCommand(name: 'native-push:send', description: 'Envoie une notification à tous les appareils d’un sujet, via les transports du Texter')]
final class SendCommand
{
    /**
     * The texter transports rather than the Texter: with Messenger installed the Texter always returns null, and a test command needs the real outcome.
     */
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire(service: 'texter.transports')] private TransportInterface $transports,
    ) {
    }

    /**
     * @param list<string> $data
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Classe de l’entité sujet, ex. App\\Entity\\User')] string $class,
        #[Argument('Identifiant du sujet')] string $id,
        #[Option('Titre')] string $title = 'Test native-push',
        #[Option('Texte')] string $body = 'Si vous lisez ceci, tout fonctionne.',
        #[Option('Page ouverte au toucher (chemin ou URL)')] ?string $url = null,
        #[Option('Donnée personnalisée cle=valeur (répétable)')] array $data = [],
    ): int {
        if (!class_exists($class) || !is_subclass_of($class, PushSubjectInterface::class)) {
            $io->error(\sprintf('%s doit être une entité qui implémente %s.', $class, PushSubjectInterface::class));

            return Command::INVALID;
        }
        $subject = $this->em->find($class, $id);
        if (null === $subject) {
            $io->error(\sprintf('%s#%s introuvable.', $class, $id));

            return Command::FAILURE;
        }

        $pairs = [];
        foreach ($data as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $pairs[$key] = $value;
        }

        try {
            $sent = $this->transports->send(new PushMessage($title, $body, NativePushOptions::to($subject)->url($url)->data($pairs)));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (null === $sent?->getMessageId()) {
            $io->warning('Aucun appareil joignable pour ce sujet (aucun appareil enregistré, ou plateforme non configurée : voir les logs).');
        } else {
            $io->success('Envoyé : '.$sent->getMessageId());
        }

        return Command::SUCCESS;
    }
}
