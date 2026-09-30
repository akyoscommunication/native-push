<?php

namespace Akyos\NativePushBundle;

use Akyos\NativePushBundle\Client\ApnsClient;
use Akyos\NativePushBundle\Client\FcmClient;
use Akyos\NativePushBundle\Controller\DeviceController;
use Akyos\NativePushBundle\SubjectResolver\SecurityUserResolver;
use Akyos\NativePushBundle\SubjectResolver\SubjectResolverInterface;
use Akyos\NativePushBundle\Transport\NativePushTransportFactory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class AkyosNativePushBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root->children()
            ->append($this->section('apns', 'iOS (APNs, authentification par clé .p8)', [
                'team_id' => 'Apple Team ID (10 caractères)',
                'key_id' => 'Key ID de la clé APNs',
                'private_key' => 'Chemin du fichier AuthKey_XXXX.p8',
                'topic' => 'Bundle ID de l’app iOS',
            ])->children()->booleanNode('sandbox')->defaultTrue()->info('true pour les builds de dev (api.sandbox.push.apple.com)')->end()->end())
            ->append($this->section('fcm', 'Android (FCM HTTP v1, compte de service Firebase)', [
                'service_account' => 'Chemin du JSON du compte de service Firebase',
            ]))
            ->scalarNode('subject_resolver')
                ->defaultValue(SecurityUserResolver::class)
                ->info('Service qui trouve le PushSubject de la requête lors de l’enregistrement d’un appareil')
            ->end()
        ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['AkyosNativePushBundle' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => $this->getPath().'/src/Entity',
            'prefix' => 'Akyos\NativePushBundle\Entity',
            'alias' => 'NativePush',
        ]]]]);
        $builder->prependExtensionConfig('framework', ['asset_mapper' => ['paths' => [
            $this->getPath().'/assets' => '@akyos/native-push',
        ]]]);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('Akyos\\NativePushBundle\\', $this->getPath().'/src/')
            ->exclude($this->getPath().'/src/{Entity,Model,Transport/NativePushOptions.php,Transport/NativePushTransport.php,Client/PushResult.php,Client/Jwt.php,Notification,AkyosNativePushBundle.php}');

        $services->get(DeviceController::class)->public()->tag('controller.service_arguments');
        $services->get(ApnsClient::class)->arg('$config', $config['apns'] ?? null);
        $services->get(FcmClient::class)->arg('$config', $config['fcm'] ?? null);
        $services->get(NativePushTransportFactory::class)->tag('texter.transport_factory');
        $services->alias(SubjectResolverInterface::class, $config['subject_resolver']);
        $services->set(SecurityUserResolver::class)->args([service('security.helper')->nullOnInvalid()]);
    }

    /**
     * Optional section: absent means the platform is disabled; present means every key is required.
     *
     * @param array<string, string> $keys
     */
    private function section(string $name, string $info, array $keys): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node->info($info.' — facultatif : sans cette section, les appareils de cette plateforme sont ignorés');
        $children = $node->children();
        foreach ($keys as $key => $keyInfo) {
            $children->scalarNode($key)->cannotBeEmpty()->info($keyInfo)->end();
        }
        $node->validate()
            ->ifTrue(static fn (array $v): bool => [] !== $v && [] !== array_diff(array_keys($keys), array_keys($v)))
            ->then(static fn (array $v) => throw new \InvalidArgumentException(\sprintf('akyos_native_push.%s : clés manquantes %s.', $name, implode(', ', array_diff(array_keys($keys), array_keys($v))))));

        return $node;
    }
}
