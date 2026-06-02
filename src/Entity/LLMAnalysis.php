<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'llm_analyses')]
class LLMAnalysis
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CheckError::class)]
    #[ORM\JoinColumn(name: 'check_error_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?CheckError $checkError = null;

    #[ORM\Column(type: 'text')]
    private string $prompt;

    #[ORM\Column(type: 'text')]
    private string $rawResponse;

    #[ORM\Column(type: 'string', length: 255)]
    private string $summary;

    #[ORM\Column(type: 'string', length: 255)]
    private string $probableCause;

    #[ORM\Column(type: 'string', length: 50)]
    private string $severity;

    #[ORM\Column(type: 'json')]
    private array $recommendations = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ?CheckError $checkError,
        string $prompt,
        string $rawResponse,
        string $summary,
        string $probableCause,
        string $severity,
        array $recommendations = []
    ) {
        $this->id = Uuid::v4()->toRfc4122();
        $this->checkError = $checkError;
        $this->prompt = $prompt;
        $this->rawResponse = $rawResponse;
        $this->summary = $summary;
        $this->probableCause = $probableCause;
        $this->severity = $severity;
        $this->recommendations = $recommendations;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCheckError(): ?CheckError
    {
        return $this->checkError;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getRawResponse(): string
    {
        return $this->rawResponse;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getProbableCause(): string
    {
        return $this->probableCause;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
