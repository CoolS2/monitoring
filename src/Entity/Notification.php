<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(columns: ['check_key'], name: 'idx_notifications_check_key')]
#[ORM\Index(columns: ['sent_at'], name: 'idx_notifications_sent_at')]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $checkKey;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;

    public function __construct(string $checkKey, string $type, string $message)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->checkKey = $checkKey;
        $this->type = $type;
        $this->message = $message;
        $this->sentAt = new \DateTimeImmutable();
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

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
