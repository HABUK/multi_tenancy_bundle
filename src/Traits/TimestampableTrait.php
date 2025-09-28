<?php

namespace Hakam\MultiTenancyBundle\Traits;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * You can use this trait to add createdAt and updatedAt fields to your entity.
 * You should also annotate your entity with HasLifecycleCallbacks attribute.
 */
trait TimestampableTrait
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeInterface $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    /**
     * @return DateTimeInterface|null
     */
    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * @return DateTimeInterface|null
     */
    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }
}
