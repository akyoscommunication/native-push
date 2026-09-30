<?php

namespace Akyos\NativePushBundle\Entity;

use Akyos\NativePushBundle\Model\Platform;
use Akyos\NativePushBundle\Model\PushSubjectInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Persistence\Proxy;

#[ORM\Entity]
#[ORM\Table(name: 'native_push_device')]
#[ORM\Index(name: 'native_push_device_subject', fields: ['subjectClass', 'subjectId'])]
class PushDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: Platform::class)]
    private Platform $platform;

    #[ORM\Column(length: 255)]
    private string $subjectClass;

    #[ORM\Column(length: 255)]
    private string $subjectId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(
        #[ORM\Column(length: 512, unique: true)]
        private string $token,
        PushSubjectInterface $subject,
        Platform $platform,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->attachTo($subject, $platform);
    }

    public static function subjectClassOf(PushSubjectInterface $subject): string
    {
        return $subject instanceof Proxy ? get_parent_class($subject) : $subject::class;
    }

    public function attachTo(PushSubjectInterface $subject, Platform $platform): void
    {
        $this->subjectClass = self::subjectClassOf($subject);
        $this->subjectId = $subject->getPushSubjectId();
        $this->platform = $platform;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPlatform(): Platform
    {
        return $this->platform;
    }

    public function getSubjectClass(): string
    {
        return $this->subjectClass;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
