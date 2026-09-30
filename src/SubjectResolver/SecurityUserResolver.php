<?php

namespace Akyos\NativePushBundle\SubjectResolver;

use Akyos\NativePushBundle\Model\PushSubjectInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * Default: the device belongs to the logged-in user, provided the user entity is a PushSubject.
 */
final class SecurityUserResolver implements SubjectResolverInterface
{
    public function __construct(private ?Security $security = null)
    {
    }

    public function resolve(Request $request): ?PushSubjectInterface
    {
        $user = $this->security?->getUser();

        return $user instanceof PushSubjectInterface ? $user : null;
    }
}
