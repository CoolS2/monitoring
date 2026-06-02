<?php

namespace App\Tests\Service;

use App\Entity\CheckResult;
use App\Service\MonitorScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MonitorSchedulerTest extends KernelTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private ?string $tempYaml = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();

        // Dynamically rebuild the schema for the test database run
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->tempYaml = tempnam(sys_get_temp_dir(), 'monitors_yaml');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager) {
            $this->entityManager->close();
            $this->entityManager = null;
        }

        if ($this->tempYaml && file_exists($this->tempYaml)) {
            unlink($this->tempYaml);
        }

        parent::tearDown();
    }

    public function testGetDueChecks(): void
    {
        // 1. Insert check results
        // website ran 10 seconds ago (interval 60 -> NOT due)
        $result1 = new CheckResult('website', 'http', true, 'OK');
        $this->entityManager->persist($result1);

        // api ran 100 seconds ago (interval 60 -> due)
        $result2 = new CheckResult('api', 'http', true, 'OK');
        $this->entityManager->persist($result2);

        $this->entityManager->flush();

        // Use Reflection to alter the read-only createdAt timestamps
        $reflection = new \ReflectionProperty(CheckResult::class, 'createdAt');
        $reflection->setValue($result1, new \DateTimeImmutable('-10 seconds'));
        $reflection->setValue($result2, new \DateTimeImmutable('-100 seconds'));

        $this->entityManager->flush();

        // Write YAML configuration
        file_put_contents($this->tempYaml, $this->getYamlContent());

        // 2. Execute scheduler
        $scheduler = new MonitorScheduler($this->entityManager, $this->tempYaml);
        $due = $scheduler->getDueChecks();

        // 3. Assertions
        $this->assertArrayHasKey('api', $due, 'api should be due (last run 100s ago, interval 60s)');
        $this->assertArrayHasKey('new_check', $due, 'new_check should be due (never run)');
        $this->assertArrayNotHasKey('website', $due, 'website should NOT be due (last run 10s ago, interval 60s)');
    }

    private function getYamlContent(): string
    {
        return <<<YAML
checks:
  website:
    type: http
    interval: 60
  api:
    type: http
    interval: 60
  new_check:
    type: http
    interval: 30
YAML;
}
}
