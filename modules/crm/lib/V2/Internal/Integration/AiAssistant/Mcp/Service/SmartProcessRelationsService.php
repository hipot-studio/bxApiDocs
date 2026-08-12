<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Relation;
use Bitrix\Crm\Relation\RelationManager;
use Bitrix\Crm\RelationIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Result;

/**
 * Encapsulates direction-aware ('parent'/'child') operations over CRM smart process relations.
 *
 * Direction semantics (relative to the smart process $spId):
 * - 'parent': the other entity (E) is a PARENT of the smart process.
 *   The smart process appears inside the card of E. Identifier = (E, $spId).
 * - 'child': the other entity (E) is a CHILD of the smart process.
 *   E appears inside the card of the smart process. Identifier = ($spId, E).
 */
final class SmartProcessRelationsService
{
	public const DIRECTION_PARENT = 'parent';
	public const DIRECTION_CHILD = 'child';

	/** Max number of non-system relations allowed per direction in a single tool call. */
	public const MAX_RELATIONS_PER_DIRECTION = 50;

	private function getRelationManager(): RelationManager
	{
		return Container::getInstance()->getRelationManager();
	}

	/**
	 * Returns non-predefined relations for the direction as [entityTypeId => isChildrenListEnabled].
	 *
	 * @return array<int, bool>
	 */
	public function currentRelations(int $spId, string $direction): array
	{
		$manager = $this->getRelationManager();
		$collection = ($direction === self::DIRECTION_PARENT)
			? $manager->getParentRelations($spId)
			: $manager->getChildRelations($spId)
		;
		$collection = $collection->filterOutPredefinedRelations();

		$result = [];
		foreach ($collection as $relation)
		{
			$otherEntityTypeId = ($direction === self::DIRECTION_PARENT)
				? $relation->getParentEntityTypeId()
				: $relation->getChildEntityTypeId()
			;
			$result[$otherEntityTypeId] = $relation->isChildrenListEnabled();
		}

		return $result;
	}

	/**
	 * Returns entity type ids that are allowed to be bound in the direction.
	 *
	 * @return int[]
	 */
	public function availableTargets(int $spId, string $direction): array
	{
		$manager = $this->getRelationManager();
		$available = ($direction === self::DIRECTION_PARENT)
			? $manager->getAvailableForParentBindingEntityTypes($spId)
			: $manager->getAvailableForChildBindingEntityTypes($spId)
		;

		return array_map('intval', array_keys($available));
	}

	public function makeIdentifier(int $spId, string $direction, int $entityTypeId): RelationIdentifier
	{
		return ($direction === self::DIRECTION_PARENT)
			? new RelationIdentifier($entityTypeId, $spId)
			: new RelationIdentifier($spId, $entityTypeId)
		;
	}

	public function bind(int $spId, string $direction, int $entityTypeId, bool $isChildrenListEnabled): Result
	{
		$relation = ($direction === self::DIRECTION_PARENT)
			? Relation::create($entityTypeId, $spId, $isChildrenListEnabled)
			: Relation::create($spId, $entityTypeId, $isChildrenListEnabled)
		;

		return $this->getRelationManager()->bindTypes($relation);
	}

	public function updateFlag(int $spId, string $direction, int $entityTypeId, bool $isChildrenListEnabled): Result
	{
		$manager = $this->getRelationManager();
		$identifier = $this->makeIdentifier($spId, $direction, $entityTypeId);
		$relation = $manager->getRelation($identifier);
		if ($relation === null)
		{
			return (new Result())->addError(
				new \Bitrix\Main\Error('Relation to update was not found.'),
			);
		}

		$relation->setChildrenListEnabled($isChildrenListEnabled);

		return $manager->updateTypesBinding($relation);
	}

	public function unbind(int $spId, string $direction, int $entityTypeId): Result
	{
		return $this->getRelationManager()->unbindTypes(
			$this->makeIdentifier($spId, $direction, $entityTypeId),
		);
	}

	/**
	 * Returns ids of the other side of predefined (system) relations in the direction.
	 * Read once before a loop to avoid re-reading the relation set per item (the
	 * relation cache is dropped on every bind/unbind), see N+1 note.
	 *
	 * @return array<int, true> map entityTypeId => true
	 */
	public function predefinedTargetIds(int $spId, string $direction): array
	{
		$manager = $this->getRelationManager();
		$collection = ($direction === self::DIRECTION_PARENT)
			? $manager->getParentRelations($spId)
			: $manager->getChildRelations($spId)
		;

		$ids = [];
		foreach ($collection as $relation)
		{
			if (!$relation->isPredefined())
			{
				continue;
			}
			$otherEntityTypeId = ($direction === self::DIRECTION_PARENT)
				? $relation->getParentEntityTypeId()
				: $relation->getChildEntityTypeId()
			;
			$ids[$otherEntityTypeId] = true;
		}

		return $ids;
	}

	/**
	 * Applies a desired set of non-system relations for one direction.
	 *
	 * Adds missing relations, updates the children-list flag of existing ones and
	 * keeps unchanged ones. When $removeMissing is true, current non-system relations
	 * absent from $desired are unbound (declarative full replacement) and the report
	 * gets a 'removed' section; otherwise only add/update is performed.
	 *
	 * @param array<int, bool> $desired map entityTypeId => isChildrenListEnabled
	 * @return array<string, list<array>>
	 */
	public function applyRelations(
		int $spId,
		string $direction,
		array $desired,
		bool $removeMissing
	): array
	{
		$available = $this->availableTargets($spId, $direction);
		$current = $this->currentRelations($spId, $direction);
		$predefinedTargetIds = $this->predefinedTargetIds($spId, $direction);

		$added = [];
		$updated = [];
		$kept = [];
		$removed = [];
		$rejected = [];

		foreach ($desired as $entityTypeId => $flag)
		{
			$entityTypeId = (int)$entityTypeId;

			if (!in_array($entityTypeId, $available, true))
			{
				$rejected[] = [
					'entityTypeId' => $entityTypeId,
					'reason' => 'Entity type is not allowed as a ' . $direction . ' for this smart process.',
				];
				continue;
			}
			if (isset($predefinedTargetIds[$entityTypeId]))
			{
				$rejected[] = [
					'entityTypeId' => $entityTypeId,
					'reason' => 'Relation is predefined (system) and can not be modified.',
				];
				continue;
			}

			if (!array_key_exists($entityTypeId, $current))
			{
				$result = $this->bind($spId, $direction, $entityTypeId, $flag);
				if ($result->isSuccess())
				{
					$added[] = ['entityTypeId' => $entityTypeId, 'isChildrenListEnabled' => $flag];
				}
				else
				{
					$rejected[] = [
						'entityTypeId' => $entityTypeId,
						'reason' => implode('; ', $result->getErrorMessages()),
					];
				}
			}
			elseif ($current[$entityTypeId] !== $flag)
			{
				$result = $this->updateFlag($spId, $direction, $entityTypeId, $flag);
				if ($result->isSuccess())
				{
					$updated[] = ['entityTypeId' => $entityTypeId, 'isChildrenListEnabled' => $flag];
				}
				else
				{
					$rejected[] = [
						'entityTypeId' => $entityTypeId,
						'reason' => implode('; ', $result->getErrorMessages()),
					];
				}
			}
			else
			{
				$kept[] = ['entityTypeId' => $entityTypeId, 'isChildrenListEnabled' => $flag];
			}
		}

		if ($removeMissing)
		{
			foreach ($current as $currentEntityTypeId => $isChildrenListEnabled)
			{
				if (array_key_exists($currentEntityTypeId, $desired))
				{
					continue;
				}

				$result = $this->unbind($spId, $direction, $currentEntityTypeId);
				if ($result->isSuccess())
				{
					$removed[] = ['entityTypeId' => $currentEntityTypeId];
				}
				else
				{
					$rejected[] = [
						'entityTypeId' => $currentEntityTypeId,
						'reason' => implode('; ', $result->getErrorMessages()),
					];
				}
			}
		}

		$report = [
			'added' => $added,
			'updated' => $updated,
			'kept' => $kept,
		];
		if ($removeMissing)
		{
			$report['removed'] = $removed;
		}
		$report['rejected'] = $rejected;

		return $report;
	}

	/**
	 * Returns true if any direction report contains rejected items.
	 *
	 * @param array<string, array> $report
	 */
	public function hasRejections(array $report): bool
	{
		foreach ($report as $directionReport)
		{
			if (!empty($directionReport['rejected']))
			{
				return true;
			}
		}

		return false;
	}
}
