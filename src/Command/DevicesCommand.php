<?php

namespace Akyos\NativePushBundle\Command;

use Akyos\NativePushBundle\PushDeviceManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'native-push:devices', description: 'Liste les appareils enregistrés')]
final class DevicesCommand
{
    public function __construct(private PushDeviceManager $devices)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Filtre Classe#id, ex. App\\Entity\\User#42')] ?string $subject = null,
    ): int {
        [$class, $id] = null !== $subject ? explode('#', $subject, 2) + [1 => null] : [null, null];
        $rows = [];
        foreach ($this->devices->all($class, $id) as $device) {
            $rows[] = [
                $device->getPlatform()->value,
                $device->getSubjectClass().'#'.$device->getSubjectId(),
                $device->getToken(),
                $device->getLastSeenAt()->format('Y-m-d H:i'),
            ];
        }
        if ([] === $rows) {
            $io->note('Aucun appareil enregistré.');

            return Command::SUCCESS;
        }
        $io->table(['Plateforme', 'Sujet', 'Token', 'Vu le'], $rows);

        return Command::SUCCESS;
    }
}
