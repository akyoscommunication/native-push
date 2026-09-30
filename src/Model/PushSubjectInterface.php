<?php

namespace Akyos\NativePushBundle\Model;

use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * Any Doctrine entity that can receive push notifications on its registered devices.
 */
interface PushSubjectInterface extends RecipientInterface
{
    public function getPushSubjectId(): string;
}
