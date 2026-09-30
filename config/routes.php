<?php

use Akyos\NativePushBundle\Controller\DeviceController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('native_push_device_register', '/native-push/devices')
        ->controller([DeviceController::class, 'register'])
        ->methods(['POST']);
    $routes->add('native_push_device_unregister', '/native-push/devices')
        ->controller([DeviceController::class, 'unregister'])
        ->methods(['DELETE']);
};
