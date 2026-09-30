<?php

namespace Akyos\NativePushBundle\Client;

final class PushResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $invalidToken,
        public readonly ?string $id,
        public readonly ?string $error,
    ) {
    }

    public static function ok(?string $id): self
    {
        return new self(true, false, $id, null);
    }

    /**
     * The provider says this token will never work again: the device must be forgotten.
     */
    public static function invalidToken(string $error): self
    {
        return new self(false, true, null, $error);
    }

    public static function error(string $error): self
    {
        return new self(false, false, null, $error);
    }
}
