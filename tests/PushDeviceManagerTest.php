<?php

namespace Akyos\NativePushBundle\Tests;

use Akyos\NativePushBundle\Client\ApnsClient;
use Akyos\NativePushBundle\Client\FcmClient;
use Akyos\NativePushBundle\Entity\PushDevice;
use Akyos\NativePushBundle\Model\Platform;
use Akyos\NativePushBundle\Model\PushSubjectInterface;
use Akyos\NativePushBundle\PushDeviceManager;
use Akyos\NativePushBundle\Transport\NativePushOptions;
use Akyos\NativePushBundle\Transport\NativePushTransport;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Notifier\Message\PushMessage;

final class PushDeviceManagerTest extends TestCase
{
    private const IOS_TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const ANDROID_TOKEN = 'cK-uL47MTHSI8hvi23DP6F:APA91bFSsuj1g6wlCshUHxzoKVWmCoQmhaxtNpeCr5TMrs';

    private EntityManager $em;
    private PushDeviceManager $manager;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(PushDevice::class)]);
        $this->manager = new PushDeviceManager($this->em);
    }

    public function testSameTokenIsNeverDuplicatedAndMovesToTheNewSubject(): void
    {
        $alice = new TestSubject('1');
        $bob = new TestSubject('2');

        $this->manager->register($alice, self::IOS_TOKEN, Platform::Ios);
        $this->manager->register($alice, self::IOS_TOKEN, Platform::Ios);
        $this->manager->register($alice, self::ANDROID_TOKEN, Platform::Android);
        self::assertCount(2, $this->manager->all());

        $this->manager->register($bob, self::IOS_TOKEN, Platform::Ios);
        self::assertCount(2, $this->manager->all(), 'Reattaching must not create a row');
        self::assertSame([self::ANDROID_TOKEN], array_map(static fn (PushDevice $d) => $d->getToken(), $this->manager->devicesOf(TestSubject::class, '1')));
        self::assertSame([self::IOS_TOKEN], array_map(static fn (PushDevice $d) => $d->getToken(), $this->manager->devicesOf(TestSubject::class, '2')));

        self::assertTrue($this->manager->unregister(self::IOS_TOKEN));
        self::assertFalse($this->manager->unregister(self::IOS_TOKEN));
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->register(new TestSubject('1'), 'not a token', Platform::Ios);
    }

    public function testTransportSkipsUnconfiguredPlatformAndPrunesDeadTokens(): void
    {
        $subject = new TestSubject('1');
        $this->manager->register($subject, self::IOS_TOKEN, Platform::Ios);
        $this->manager->register($subject, self::ANDROID_TOKEN, Platform::Android);

        $fcm = new FcmClient(new MockHttpClient([
            new JsonMockResponse(['access_token' => 'ya29.test']),
            new JsonMockResponse(['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]], ['http_code' => 404]),
        ]), new ArrayAdapter(), ['service_account' => json_encode(ClientsTest::serviceAccount())]);
        $transport = new NativePushTransport(new ApnsClient(new MockHttpClient(), new ArrayAdapter()), $fcm, $this->manager);

        try {
            $transport->send(new PushMessage('Titre', 'Texte', NativePushOptions::to($subject)));
            self::fail('Every attempted send failed, the transport must say so');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('FCM NOT_FOUND', $e->getMessage());
        }

        self::assertSame([self::IOS_TOKEN], array_map(static fn (PushDevice $d) => $d->getToken(), $this->manager->all()), 'Unconfigured iOS kept, dead Android pruned');

        $sent = $transport->send(new PushMessage('Titre', 'Texte', NativePushOptions::to(new TestSubject('nobody'))));
        self::assertNull($sent->getMessageId(), 'A subject without devices is not an error');
    }
}

final class TestSubject implements PushSubjectInterface
{
    public function __construct(private string $id)
    {
    }

    public function getPushSubjectId(): string
    {
        return $this->id;
    }
}
