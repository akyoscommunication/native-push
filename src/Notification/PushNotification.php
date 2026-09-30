<?php

namespace Akyos\NativePushBundle\Notification;

use Akyos\NativePushBundle\Model\PushSubjectInterface;
use Akyos\NativePushBundle\Transport\NativePushOptions;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notification\PushNotificationInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * $notifier->send(new PushNotification('Titre', 'Texte', url: '/orders/42'), $subject);
 */
class PushNotification extends Notification implements PushNotificationInterface
{
    /**
     * @param array<string, scalar> $data
     */
    public function __construct(
        string $subject,
        string $content = '',
        private ?string $url = null,
        private array $data = [],
        private ?int $badge = null,
    ) {
        parent::__construct($subject, ['push']);
        $this->content($content);
    }

    public function asPushMessage(RecipientInterface $recipient, ?string $transport = null): ?PushMessage
    {
        if (!$recipient instanceof PushSubjectInterface) {
            throw new \InvalidArgumentException(\sprintf('Le destinataire d’une %s doit implémenter %s.', self::class, PushSubjectInterface::class));
        }

        return new PushMessage($this->getSubject(), $this->getContent(), NativePushOptions::to($recipient)->url($this->url)->data($this->data)->badge($this->badge));
    }
}
