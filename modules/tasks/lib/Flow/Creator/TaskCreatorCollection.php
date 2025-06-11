<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Creator;

use Bitrix\Main\Access\AccessCode;
use Closure;

class TaskCreatorCollection
{
	protected array $accessCodes = [];

	public function __construct(string ...$accessCodes)
	{
		$this->accessCodes = $accessCodes;
	}

	public function getIdList(): array
	{
		$ids = array_map(static fn(string $code): int => (new AccessCode($code))->getEntityId(), $this->accessCodes);
		return array_filter($ids);
	}

	public function getUsers(): static
	{
		$users = array_filter($this->accessCodes, $this->getFilter(AccessCode::TYPE_USER));
		return new static(...$users);
	}

	public function getDepartments(): static
	{
		$departments = array_filter($this->accessCodes, $this->getFilter(AccessCode::TYPE_DEPARTMENT));
		return new static(...$departments);
	}

	public function hasUserAll(): bool
	{
		return in_array('UA', $this->accessCodes, true);
	}

	private function getFilter(string $entityType): Closure
	{
		return static fn (string $code): bool => (new AccessCode($code))->getEntityType() === $entityType;
	}
}
