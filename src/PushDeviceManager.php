<?php

namespace Akyos\NativePushBundle;

use Akyos\NativePushBundle\Entity\PushDevice;
use Akyos\NativePushBundle\Model\Platform;
use Akyos\NativePushBundle\Model\PushSubjectInterface;
use Doctrine\ORM\EntityManagerInterface;

final class PushDeviceManager
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * A token belongs to one subject only: registering it again moves it (a phone switching account).
     *
     * ponytail: two simultaneous first registrations of the same token hit the unique index and the loser
     * gets a UniqueConstraintViolationException; the app simply retries on next launch. Wrap in a retry if that matters.
     */
    public function register(PushSubjectInterface $subject, string $token, Platform $platform): PushDevice
    {
        if (!$platform->isValidToken($token)) {
            throw new \InvalidArgumentException(\sprintf('Token %s invalide.', $platform->value));
        }

        $device = $this->em->getRepository(PushDevice::class)->findOneBy(['token' => $token]);
        if (null === $device) {
            $device = new PushDevice($token, $subject, $platform);
            $this->em->persist($device);
        } else {
            $device->attachTo($subject, $platform);
        }
        $this->em->flush();

        return $device;
    }

    public function unregister(string $token): bool
    {
        return $this->deleteByTokens([$token]) > 0;
    }

    /**
     * @return list<PushDevice>
     */
    public function devicesOf(string $subjectClass, string $subjectId): array
    {
        return $this->em->getRepository(PushDevice::class)->findBy(['subjectClass' => $subjectClass, 'subjectId' => $subjectId]);
    }

    /**
     * @return list<PushDevice>
     */
    public function all(?string $subjectClass = null, ?string $subjectId = null): array
    {
        return $this->em->getRepository(PushDevice::class)->findBy(array_filter(['subjectClass' => $subjectClass, 'subjectId' => $subjectId], static fn (?string $v): bool => null !== $v), ['lastSeenAt' => 'DESC']);
    }

    /**
     * @param list<string> $tokens
     */
    public function deleteByTokens(array $tokens): int
    {
        if ([] === $tokens) {
            return 0;
        }

        return $this->em->createQuery(\sprintf('DELETE FROM %s d WHERE d.token IN (:tokens)', PushDevice::class))
            ->setParameter('tokens', $tokens)
            ->execute();
    }
}
