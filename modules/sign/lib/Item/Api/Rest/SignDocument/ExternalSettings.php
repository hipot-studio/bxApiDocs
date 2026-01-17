<?php
declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

use JsonSerializable;

/**
 * Class ExternalSettings
 *
 * Represents external settings for sign document requests.
 */
final class ExternalSettings implements JsonSerializable
{
    private ?string $externalId = null;
    private ?string $externalDateCreate = null;

    /**
     * ExternalSettings constructor.
     *
     * @param array{externalId?: string, externalDateCreate?: string} $data
     */
    public function __construct(array $data = [])
    {
        if (isset($data['externalId']))
		{
            $this->externalId = (string)$data['externalId'];
        }

        if (isset($data['externalDateCreate']))
		{
				$this->externalDateCreate = (string)$data['externalDateCreate'];
        }
    }

    /**
     * Create instance from array.
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Convert object to array.
     *
     * @return array{
     *     externalId?: string|null,
     *     externalDateCreate?: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'externalId' => $this->externalId,
            'externalDateCreate' => $this->externalDateCreate,
        ];
    }

    /**
     * Specify data for JSON serialization.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // Getters and setters

    /** @return string|null */
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /** @param string|null $id */
    public function setExternalId(?string $id): void
    {
        $this->externalId = $id;
    }

    /** @return string|null */
    public function getExternalDateCreate(): ?string
    {
        return $this->externalDateCreate;
    }

    /** @param string|null $date */
    public function setExternalDateCreate(?string $date): void
    {
        $this->externalDateCreate = $date;
    }
}