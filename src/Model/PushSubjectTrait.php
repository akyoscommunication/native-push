<?php

namespace Akyos\NativePushBundle\Model;

/**
 * Default PushSubjectInterface implementation for entities exposing getId().
 */
trait PushSubjectTrait
{
    public function getPushSubjectId(): string
    {
        $id = $this->getId();
        if (null === $id) {
            throw new \LogicException(\sprintf('%s doit être persisté avant de recevoir des notifications push.', static::class));
        }

        return (string) $id;
    }
}
