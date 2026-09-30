<?php

namespace Akyos\NativePushBundle\Transport;

use Akyos\NativePushBundle\Entity\PushDevice;
use Akyos\NativePushBundle\Model\Platform;
use Akyos\NativePushBundle\Model\PushSubjectInterface;
use Symfony\Component\Notifier\Message\MessageOptionsInterface;

/**
 * Scalars only, so a PushMessage carrying these options can go through Messenger.
 */
final class NativePushOptions implements MessageOptionsInterface
{
    private ?string $url = null;
    /** @var array<string, scalar> */
    private array $data = [];
    private ?int $badge = null;
    private ?string $sound = 'default';

    /**
     * @param list<array{string, string}> $tokens [token, platform] pairs, used instead of a subject
     */
    private function __construct(
        private ?string $subjectClass,
        private ?string $subjectId,
        private array $tokens = [],
    ) {
    }

    public static function to(PushSubjectInterface $subject): self
    {
        return new self(PushDevice::subjectClassOf($subject), $subject->getPushSubjectId());
    }

    /**
     * @param list<string> $tokens
     */
    public static function tokens(array $tokens, Platform $platform): self
    {
        return new self(null, null, array_map(static fn (string $t): array => [$t, $platform->value], array_values($tokens)));
    }

    /**
     * Page opened when the notification is tapped: a path resolved against the app base URL, or an absolute URL.
     */
    public function url(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @param array<string, scalar> $data
     */
    public function data(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function badge(?int $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    public function sound(?string $sound): self
    {
        $this->sound = $sound;

        return $this;
    }

    public function getSubjectClass(): ?string
    {
        return $this->subjectClass;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    /**
     * @return list<array{string, Platform}>
     */
    public function getTokens(): array
    {
        return array_map(static fn (array $t): array => [$t[0], Platform::from($t[1])], $this->tokens);
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @return array<string, scalar>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getBadge(): ?int
    {
        return $this->badge;
    }

    public function getSound(): ?string
    {
        return $this->sound;
    }

    public function getRecipientId(): ?string
    {
        return null !== $this->subjectClass ? $this->subjectClass.'#'.$this->subjectId : null;
    }

    public function toArray(): array
    {
        return [
            'subject' => $this->getRecipientId(),
            'tokens' => $this->tokens,
            'url' => $this->url,
            'data' => $this->data,
            'badge' => $this->badge,
            'sound' => $this->sound,
        ];
    }
}
