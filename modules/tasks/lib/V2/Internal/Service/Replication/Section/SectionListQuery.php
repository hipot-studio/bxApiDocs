<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Section;

use Bitrix\Main\UI\Filter\NumberType;
use Bitrix\Tasks\Internals\Task\Priority;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;

/**
 * Slice query parameters of the "Regular tasks" section.
 *
 * `viewerId` - the viewer from the server context (not from the client request);
 * `roleFilter` - a role type (`TemplateMemberTable::MEMBER_TYPE_*`) or null for "All roles";
 * `sortDirection` - `asc`/`desc`, or null for "the user has not picked one" (the sorting keeps
 * its own default direction);
 * `templateIds` - an optional bounded subset used by realtime row refreshes.
 * `filter` - the allowlisted values of the standard task-list fields and their mode satellites.
 * `visibleColumnIds` - the server-normalized grid columns that need row data.
 */
class SectionListQuery
{
	private const ID_FILTER_FIELDS = [
		'RESPONSIBLE_ID',
		'CREATED_BY',
		'GROUP_ID',
		'ID',
		'AUDITOR',
		'ACCOMPLICE',
	];
	private const PRIORITY_FILTER_VALUES = [Priority::AVERAGE, Priority::HIGH];

	public function __construct(
		public readonly int $viewerId,
		public readonly ?string $roleFilter = null,
		public readonly ?bool $replicateActive = true,
		public readonly int $limit = 50,
		public readonly int $offset = 0,
		public readonly ?string $search = null,
		public readonly string $groupBy = SectionSettings::DEFAULT_GROUP_BY,
		public readonly string $sortBy = SectionSettings::DEFAULT_SORT_BY,
		public readonly ?string $sortDirection = null,
		public readonly array $templateIds = [],
		public readonly array $filter = [],
		public readonly array $visibleColumnIds = [],
	)
	{
	}

	/**
	 * Whether a `filter` value restricts the slice. Mirrors the conditions
	 * {@see ReplicationRowService} builds from `filter`: a value it skips narrows nothing.
	 * The client half is `hasNarrowingSectionFilter()` of tasks.v2.application.replication.
	 */
	public function hasNarrowingFilter(): bool
	{
		foreach (self::ID_FILTER_FIELDS as $field)
		{
			if (self::toPositiveIds($this->filter[$field] ?? null) !== [])
			{
				return true;
			}
		}

		$priorities = self::toPositiveIds($this->filter['PRIORITY'] ?? null);

		return array_intersect($priorities, self::PRIORITY_FILTER_VALUES) !== []
			|| $this->hasRegularityTypeFilter()
			|| $this->hasIdNumberFilter()
		;
	}

	private function hasRegularityTypeFilter(): bool
	{
		$value = $this->filter['REPLICATE_TYPE'] ?? null;
		foreach (is_array($value) ? $value : [$value] as $item)
		{
			if (
				$item !== null
				&& $item !== ''
				&& $item !== false
				&& is_scalar($item)
				&& RegularityType::tryFrom((int)$item) !== null
			)
			{
				return true;
			}
		}

		return false;
	}

	private function hasIdNumberFilter(): bool
	{
		$hasFrom = self::toPositiveId($this->filter['ID_from'] ?? null) !== null;
		$hasTo = self::toPositiveId($this->filter['ID_to'] ?? null) !== null;

		return match ($this->filter['ID_numsel'] ?? null)
		{
			NumberType::SINGLE, NumberType::RANGE => $hasFrom || $hasTo,
			NumberType::MORE => $hasFrom,
			NumberType::LESS => $hasTo,
			default => false,
		};
	}

	/**
	 * @return int[]
	 */
	private static function toPositiveIds(mixed $value): array
	{
		$ids = [];
		foreach (is_array($value) ? $value : [$value] as $item)
		{
			$id = self::toPositiveId($item);
			if ($id !== null)
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	private static function toPositiveId(mixed $value): ?int
	{
		if (!is_scalar($value))
		{
			return null;
		}

		$id = (int)$value;

		return $id > 0 ? $id : null;
	}
}
