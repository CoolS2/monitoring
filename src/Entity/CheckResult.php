<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'check_results')]
#[ORM\Index(columns: ['check_key'], name: 'idx_check_results_check_key')]
#[ORM\Index(columns: ['created_at'], name: 'idx_check_results_created_at')]
class CheckResult
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $checkKey;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(type: 'boolean')]
    private bool $success;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $responseTime = null;

    #[ORM\Column(type: 'json')]
    private array $extra = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $checkKey,
        string $type,
        bool $success,
        string $message,
        ?float $responseTime = null,
        array $extra = []
    ) {
        $this->id = Uuid::v4()->toRfc4122();
        $this->checkKey = $checkKey;
        $this->type = $type;
        $this->success = $success;
        $this->message = $message;
        $this->responseTime = $responseTime;
        $this->extra = $extra;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCheckKey(): string
    {
        return $this->checkKey;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getResponseTime(): ?float
    {
        return $this->responseTime;
    }

    public function getExtra(): array
    {
        return $this->extra;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
