<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'check_errors')]
#[ORM\Index(columns: ['check_key'], name: 'idx_check_errors_check_key')]
#[ORM\Index(columns: ['resolved_at'], name: 'idx_check_errors_resolved_at')]
class CheckError
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $checkKey;

    #[ORM\Column(type: 'string', length: 255)]
    private string $message;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(string $checkKey, string $message, ?string $details = null)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->checkKey = $checkKey;
        $this->message = $message;
        $this->details = $details;
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

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolve(): void
    {
        $this->resolvedAt = new \DateTimeImmutable();
    }
}
