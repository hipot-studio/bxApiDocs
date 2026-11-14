<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

/**
 * What a list of activities is narrowed by: an optional binding the activity must have, joined by AND to
 * conditions over the fields of the model, and the activities left out by their identifiers.
 *
 * The conditions name fields of {@see Activity} and hold values of the model (enums, dates), not columns
 * and stored values: turning them into a statement is the business of the storage.
 */
final class ActivityFilter
{
	public const FIELDS = [
		Activity::id,
		Activity::type,
		Activity::providerId,
		Activity::providerTypeId,
		Activity::isCompleted,
		Activity::responsibleId,
		Activity::direction,
		Activity::createdTime,
		Activity::updatedTime,
		Activity::startTime,
		Activity::deadline,
	];

	private readonly ConditionTree $conditions;

	/** @var int[] */
	private readonly array $excludedIds;

	/**
	 * @param int[] $excludedIds activities left out of the list whatever the rest of the filter says - the ones a
	 *        contextual list was refused - so that they are out before the list is cut into pages
	 * @throws ArgumentException a condition names a field outside {@see self::FIELDS}.
	 */
	public function __construct(
		private readonly ?ActivityBinding $binding = null,
		?ConditionTree $conditions = null,
		array $excludedIds = [],
	)
	{
		$this->conditions = $conditions !== null ? clone $conditions : new ConditionTree();
		self::requireKnownFields($this->conditions);

		$excluded = [];
		foreach ($excludedIds as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$excluded[$id] = $id;
			}
		}
		$this->excludedIds = array_values($excluded);
	}

	public function getBinding(): ?ActivityBinding
	{
		return $this->binding;
	}

	public function getConditions(): ConditionTree
	{
		return clone $this->conditions;
	}

	/**
	 * @return int[]
	 */
	public function getExcludedIds(): array
	{
		return $this->excludedIds;
	}

	private static function requireKnownFields(ConditionTree $tree): void
	{
		foreach ($tree->getConditions() as $condition)
		{
			if ($condition instanceof ConditionTree)
			{
				self::requireKnownFields($condition);

				continue;
			}

			$field = $condition instanceof Condition ? $condition->getColumn() : null;
			if (!is_string($field) || !in_array($field, self::FIELDS, true))
			{
				throw new ArgumentException('The activity list cannot be filtered by this field.', 'conditions');
			}
		}
	}
}
