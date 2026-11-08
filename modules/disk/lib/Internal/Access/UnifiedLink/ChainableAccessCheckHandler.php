<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\BaseObject;

abstract class ChainableAccessCheckHandler implements AccessCheckHandler
{
	protected ?AccessCheckHandler $next = null;
	/** @var array<int, UnifiedLinkAccessLevel> */
	protected array $collectionCache = [];

	public function check(BaseObject $baseObject, ?UnifiedLinkAccessLevel $previousResult = null): UnifiedLinkAccessLevel
	{
		$result = $this->doCheck($baseObject);
		$result = (isset($previousResult) && $result->value < $previousResult->value)
			? $previousResult
			: $result;

		if ($this->next !== null && !$result->isMax())
		{
			return $this->next->check($baseObject, $result);
		}

		return $result;
	}

	public function checkCollection(array $baseObjects): array
	{
		$result = $this->getCurrentCollectionAccessLevels($baseObjects);

		if ($this->next instanceof AccessCheckHandler)
		{
			$baseObjectsForNext = $this->filterNotMaxAccessLevelObjects($baseObjects, $result);

			if (empty($baseObjectsForNext))
			{
				return $result;
			}

			$result = $this->mergeCollectionAccessLevels(
				current: $result,
				next: $this->next->checkCollection($baseObjectsForNext),
			);
		}

		return $result;
	}

	public function checkAll(BaseObject $baseObject): UnifiedLinkAccessResult
	{
		$result = new UnifiedLinkAccessResult();
		$current = $this;

		while ($current instanceof self)
		{
			$result->addAccessLevel(
				source: $current->getAccessSource(),
				accessLevel: $current->doCheck($baseObject),
			);

			$current = $current->next;
		}

		return $result;
	}

	public function checkAllCollection(array $baseObjects): array
	{
		$results = [];
		$objectsToCheck = $baseObjects;
		$current = $this;

		foreach ($baseObjects as $objectId => $baseObject)
		{
			$results[$objectId] = new UnifiedLinkAccessResult();
		}

		while ($current instanceof self && !empty($objectsToCheck))
		{
			$accessLevels = $current->getCurrentCollectionAccessLevels($objectsToCheck);

			foreach ($objectsToCheck as $objectId => $baseObject)
			{
				$results[$objectId]->addAccessLevel(
					source: $current->getAccessSource(),
					accessLevel: $accessLevels[$objectId] ?? UnifiedLinkAccessLevel::Denied,
				);
			}

			$objectsToCheck = $current->filterNotMaxAccessLevelObjects($objectsToCheck, $accessLevels);
			$current = $current->next;
		}

		return $results;
	}

	protected function getCurrentCollectionAccessLevels(array $baseObjects): array
	{
		$result = [];
		$objectsToCheck = [];

		foreach ($baseObjects as $objectId => $baseObject)
		{
			if (isset($this->collectionCache[$objectId]))
			{
				$result[$objectId] = $this->collectionCache[$objectId];
			}
			else
			{
				$objectsToCheck[$objectId] = $baseObject;
			}
		}

		if (empty($objectsToCheck))
		{
			return $result;
		}

		$checkedAccessLevels = $this->doCheckCollection($objectsToCheck);

		foreach ($objectsToCheck as $objectId => $baseObject)
		{
			$accessLevel = $checkedAccessLevels[$objectId] ?? UnifiedLinkAccessLevel::Denied;
			$this->collectionCache[$objectId] = $accessLevel;
			$result[$objectId] = $accessLevel;
		}

		return $result;
	}

	abstract protected function doCheck(BaseObject $baseObject): UnifiedLinkAccessLevel;

	abstract protected function doCheckCollection(array $baseObjects): array;

	abstract protected function getAccessSource(): UnifiedLinkAccessSource;

	protected function filterNotMaxAccessLevelObjects(array $baseObjects, array $accessLevels): array
	{
		$result = [];

		foreach ($baseObjects as $objectId => $baseObject)
		{
			$accessLevel = $accessLevels[$objectId] ?? null;

			if (
				!$accessLevel instanceof UnifiedLinkAccessLevel
				|| !$accessLevel->isMax()
			)
			{
				$result[$objectId] = $baseObject;
			}
		}

		return $result;
	}

	protected function mergeCollectionAccessLevels(array $current, array $next): array
	{
		foreach ($next as $objectId => $accessLevel)
		{
			if (!$accessLevel instanceof UnifiedLinkAccessLevel)
			{
				continue;
			}

			if (
				!isset($current[$objectId])
				|| !$current[$objectId] instanceof UnifiedLinkAccessLevel
				|| $accessLevel->value > $current[$objectId]->value
			)
			{
				$current[$objectId] = $accessLevel;
			}
		}

		return $current;
	}

	public function append(AccessCheckHandler $handler): AccessCheckHandler
	{
		if (is_null($this->next))
		{
			$this->next = $handler;
		}
		else
		{
			$this->next->append($handler);
		}

		return $this;
	}
}
