<?php

declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Company request data
 */
final class CompanyRequestData
{
    private ?string $uuid;
    private ?int $crmId;

    public function __construct(?string $uuid = null, ?int $crmId = null)
    {
        $this->uuid = $uuid;
        $this->crmId = $crmId;
    }

    /**
     * Create instance from array
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $uuid = $data['uuid'] ?? null;
        $crmId = isset($data['crmId']) ? (int)$data['crmId'] : null;

        return new self($uuid, $crmId);
    }

    /**
     * Convert object to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'crmId' => $this->crmId,
        ];
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function getCrmId(): ?int
    {
        return $this->crmId;
    }
}

