<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Filter;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetTagTable;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\ScopeMap;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\UserTable;

final class StaleConditionResolver
{
	public const REASON_DELETED = 'deleted';
	public const REASON_INACCESSIBLE = 'inaccessible';

	/**
	 * Both sections name their groups 'GROUPS.ID', and the groups behind that name are different
	 * entities on different axes, so the section the preset belongs to is asked for up front.
	 */
	public const SECTION_DASHBOARD = 'dashboard';
	public const SECTION_TABLE = 'table';

	private const FIELD_GROUPS = 'GROUPS.ID';

	private const CHECKED_FIELD_IDS = [
		self::SECTION_DASHBOARD => [
			self::FIELD_GROUPS,
			'SOURCE_ID',
			'TAGS.ID',
			'SCOPE.SCOPE_CODE',
			'URL_PARAMS.CODE',
			'CREATED_BY_ID',
		],
		self::SECTION_TABLE => [
			self::FIELD_GROUPS,
		],
	];

	/** Fields whose values are ids, and the table each id has to be alive in. */
	private const ENTITY_TABLES = [
		self::SECTION_DASHBOARD => [
			self::FIELD_GROUPS => SupersetDashboardGroupTable::class,
			'SOURCE_ID' => SupersetDashboardTable::class,
			'TAGS.ID' => SupersetTagTable::class,
			'CREATED_BY_ID' => UserTable::class,
		],
		self::SECTION_TABLE => [
			self::FIELD_GROUPS => TableGroupTable::class,
		],
	];

	private const LABEL_SUFFIX = '_label';

	/** Ids alive in each table, read once per resolve(). */
	private array $alive = [];

	/** Ids the user may see in each table; null when the table carries no restriction for them. */
	private array $allowed = [];

	public function __construct(
		private readonly ?int $userId = null,
		private readonly string $section = self::SECTION_DASHBOARD,
	)
	{
	}

	public static function forTableSection(?int $userId = null): self
	{
		return new self($userId, self::SECTION_TABLE);
	}

	/**
	 * Takes preset values as Options stores them and drops references that no longer resolve.
	 * Never reads titles of entities the user cannot see: labels come from the preset itself.
	 */
	public function resolve(array $fields): StaleConditionResult
	{
		$this->prefetch($fields);

		$stale = [];

		foreach (self::CHECKED_FIELD_IDS[$this->section] as $fieldId)
		{
			if (!isset($fields[$fieldId]))
			{
				continue;
			}

			$isScalar = !is_array($fields[$fieldId]);
			$values = array_values((array)$fields[$fieldId]);
			$labels = array_values((array)($fields[$fieldId . self::LABEL_SUFFIX] ?? []));

			$alive = [];
			$dropped = [];

			foreach ($values as $index => $value)
			{
				if ((string)$value === '')
				{
					continue;
				}

				$reason = $this->checkValue($fieldId, (string)$value);
				if ($reason === null)
				{
					$alive[] = $value;

					continue;
				}

				$dropped[$reason][] = (string)($labels[$index] ?? '');
			}

			if (empty($dropped))
			{
				continue;
			}

			foreach ($dropped as $reason => $droppedLabels)
			{
				$stale[] = [
					'fieldId' => $fieldId,
					'values' => array_values(array_filter($droppedLabels, static fn(string $l): bool => $l !== '')),
					'reason' => $reason,
				];
			}

			unset($fields[$fieldId], $fields[$fieldId . self::LABEL_SUFFIX]);

			if (!empty($alive))
			{
				$fields[$fieldId] = $isScalar ? $alive[0] : $alive;
			}
		}

		return new StaleConditionResult($fields, $stale);
	}

	private function checkValue(string $fieldId, string $value): ?string
	{
		if ($this->section === self::SECTION_TABLE)
		{
			return match ($fieldId)
			{
				self::FIELD_GROUPS => $this->checkEntity(TableGroupTable::class, (int)$value),
				default => null,
			};
		}

		return match ($fieldId)
		{
			self::FIELD_GROUPS => $this->checkEntity(SupersetDashboardGroupTable::class, (int)$value),
			'SOURCE_ID' => $this->checkEntity(SupersetDashboardTable::class, (int)$value),
			'TAGS.ID' => $this->exists(SupersetTagTable::class, (int)$value) ? null : self::REASON_DELETED,
			'SCOPE.SCOPE_CODE' => in_array($value, ScopeService::getInstance()->getScopeList(false), true)
				? null
				: self::REASON_DELETED,
			'URL_PARAMS.CODE' => $this->isKnownParameter($value) ? null : self::REASON_DELETED,
			'CREATED_BY_ID' => $this->exists(UserTable::class, (int)$value) ? null : self::REASON_DELETED,
			default => null,
		};
	}

	/**
	 * Existence is read without the access filter on purpose: otherwise a deleted entity
	 * cannot be told apart from one the user simply may not see.
	 */
	private function checkEntity(string $tableClass, int $id): ?string
	{
		if (!$this->exists($tableClass, $id))
		{
			return self::REASON_DELETED;
		}

		return $this->isAccessible($tableClass, $id) ? null : self::REASON_INACCESSIBLE;
	}

	private function exists(string $tableClass, int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		if (array_key_exists($tableClass, $this->alive))
		{
			return isset($this->alive[$tableClass][$id]);
		}

		// A table nobody prefetched: a field added to checkValue() alone still answers correctly.
		/** @var DataManager $tableClass */
		return $tableClass::getRow(['select' => ['ID'], 'filter' => ['=ID' => $id]]) !== null;
	}

	private function isAccessible(string $tableClass, int $id): bool
	{
		$allowed = $this->allowedIds($tableClass);

		// null means no restriction at all (admin bypass / no filter rule).
		return $allowed === null || isset($allowed[$id]);
	}

	/**
	 * The access filter depends on the table and the user, not on the value, so it is read once
	 * per resolve() instead of once per id.
	 */
	private function allowedIds(string $tableClass): ?array
	{
		if (!array_key_exists($tableClass, $this->allowed))
		{
			$this->allowed[$tableClass] = $tableClass === TableGroupTable::class
				? $this->allowedTableGroupIds()
				: $this->allowedDashboardIds($tableClass)
			;
		}

		return $this->allowed[$tableClass];
	}

	private function allowedDashboardIds(string $tableClass): ?array
	{
		$filter = $this->controller()->getEntityFilter(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $tableClass);

		return empty($filter)
			? null
			: array_fill_keys(array_map('intval', (array)($filter['=ID'] ?? [])), true)
		;
	}

	/**
	 * The axis of the tables answers by groups alone - it carries no filter factory - so the allowed
	 * ids are asked of the rule itself. An administrator gets every group of the axis back.
	 *
	 * Null is read here as "no group at all", the same way the repository of the groups reads it: the
	 * axis grants by naming its groups, so an answer that names none grants none. Reading it as "no
	 * restriction" would keep a condition on a group the person may not see, and the next consumer of
	 * the allowed groups would have one more example of the permissive reading to copy.
	 */
	private function allowedTableGroupIds(): array
	{
		$allowedGroupIds = $this->controller()->getAllowedGroupValue(ActionDictionary::ACTION_BIC_TABLE_VIEW);

		return array_fill_keys(array_map('intval', $allowedGroupIds ?? []), true);
	}

	private function controller(): AccessController
	{
		return $this->userId === null
			? AccessController::getCurrent()
			: new AccessController($this->userId)
		;
	}

	/**
	 * One query per table instead of one per value: a preset names several groups, tags and
	 * reports at once, and the list asks for them again on every page it loads.
	 */
	private function prefetch(array $fields): void
	{
		$this->alive = [];
		$this->allowed = [];

		foreach (self::ENTITY_TABLES[$this->section] as $fieldId => $tableClass)
		{
			if (!isset($fields[$fieldId]))
			{
				continue;
			}

			$ids = [];

			foreach ((array)$fields[$fieldId] as $value)
			{
				$id = (int)$value;

				if ($id > 0)
				{
					$ids[$id] = true;
				}
			}

			$this->alive[$tableClass] = $ids === [] ? [] : $this->fetchAliveIds($tableClass, array_keys($ids));
		}
	}

	private function fetchAliveIds(string $tableClass, array $ids): array
	{
		/** @var DataManager $tableClass */
		$rows = $tableClass::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => $ids],
		])->fetchAll();

		return array_fill_keys(array_map('intval', array_column($rows, 'ID')), true);
	}

	private function isKnownParameter(string $code): bool
	{
		foreach (ScopeMap::getAvailableParameters() as $parameter)
		{
			if ($parameter->code() === $code)
			{
				return true;
			}
		}

		return false;
	}
}
