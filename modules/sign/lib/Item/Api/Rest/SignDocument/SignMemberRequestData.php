<?php

declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Sign member request data
 */
final class SignMemberRequestData
{
    private ?string $employeeCode;
    private ?int $employeeId;
    private ?int $userId;
    private ?string $role;

    public function __construct(?string $employeeCode = null, ?int $employeeId = null, ?int $userId = null, ?string $role = null)
    {
        $this->employeeCode = $employeeCode;
        $this->employeeId = $employeeId;
        $this->userId = $userId;
        $this->role = $role;
    }

    /**
     * Create instance from array
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $employeeCode = $data['employeeCode'] ?? null;
        $employeeId = isset($data['employeeId']) ? (int)$data['employeeId'] : null;
        $userId = isset($data['userId']) ? (int)$data['userId'] : null;
        $role = $data['role'] ?? null;

        return new self($employeeCode, $employeeId, $userId, $role);
    }

    /**
     * Convert object to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'employeeCode' => $this->employeeCode,
            'employeeId' => $this->employeeId,
            'userId' => $this->userId,
            'role' => $this->role,
        ];
    }

    public function getEmployeeCode(): ?string
    {
        return $this->employeeCode;
    }

    public function getEmployeeId(): ?int
    {
        return $this->employeeId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }
}

