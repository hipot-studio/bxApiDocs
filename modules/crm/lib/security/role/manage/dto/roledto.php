<?php

namespace Bitrix\Crm\Security\Role\Manage\DTO;

class roledto
{
    public function __construct(
        private int $id,
        private string $name,
        private string $isSystem,
        private ?string $code = null,
        private ?string $groupCode = null
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function code(): ?string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isSystem(): string
    {
        return $this->isSystem;
    }

    public function groupCode(): ?string
    {
        return $this->groupCode;
    }

    public static function createFromDbRow(array $dbRow): self
    {
        return new self(
            (int) $dbRow['ID'],
            $dbRow['NAME'],
            'Y' === $dbRow['IS_SYSTEM'] ? 'Y' : 'N',
            $dbRow['CODE'] ?? null,
            $dbRow['GROUP_CODE'] ?? null,
        );
    }

    public static function createBlank(): self
    {
        return new self(0, '', 'N');
    }
}
