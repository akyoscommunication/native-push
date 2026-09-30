<?php

namespace Akyos\NativePushBundle\SubjectResolver;

use Akyos\NativePushBundle\Model\PushSubjectInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides who owns the device registering its token. Return null to refuse (401).
 */
interface SubjectResolverInterface
{
    public function resolve(Request $request): ?PushSubjectInterface;
}
