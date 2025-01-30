<?php

namespace Bitrix\Crm\Filter\Activity;

final class prepareresult
{
    private array $filter;

    private ?array $counterUserIds;

    private ?bool $excludeUsers;

    private ?int $counterTypeId;

    private bool $isApplyCounterFilter;

    public function __construct(
        array $filter,
        ?array $counterUserIds = null,
        ?bool $excludeUsers = null,
        ?int $counterTypeId = null,
        ?bool $isApplyCounterFilter = false
    ) {
        $this->filter = $filter;
        $this->counterUserIds = $counterUserIds;
        $this->excludeUsers = $excludeUsers;
        $this->counterTypeId = $counterTypeId;
        $this->isApplyCounterFilter = $isApplyCounterFilter;
    }

    public function filter(): array
    {
        return $this->filter;
    }

    public function counterUserIds(): ?array
    {
        return $this->counterUserIds;
    }

    public function isExcludeUsers(): ?bool
    {
        return $this->excludeUsers;
    }

    public function willApplyCounterFilter(): bool
    {
        return $this->isApplyCounterFilter;
    }

    public function counterTypeId(): ?int
    {
        return $this->counterTypeId;
    }
}
