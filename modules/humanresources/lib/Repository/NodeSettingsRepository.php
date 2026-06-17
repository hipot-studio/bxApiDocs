<?php

namespace Bitrix\HumanResources\Repository;

use Bitrix\HumanResources\Enum\EventName;
use Bitrix\HumanResources\Exception\CreationFailedException;
use Bitrix\HumanResources\Exception\DeleteFailedException;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Model\NodeSettingsTable;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeSettingsType;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\DateTime;

class NodeSettingsRepository
{
	/**
	 * @param array $nodeSettings
	 * @return Item\NodeSettings
	 */
	protected function convertModelArrayToItem(array $nodeSettings): Item\NodeSettings
	{
		return new Item\NodeSettings(
			nodeId: $nodeSettings['NODE_ID'],
			settingsType: NodeSettingsType::tryFrom($nodeSettings['SETTINGS_TYPE']),
			settingsValue: $nodeSettings['SETTINGS_VALUE'],
			id: $nodeSettings['ID'],
			createdAt: $nodeSettings['CREATED_AT'],
			updatedAt: $nodeSettings['UPDATED_AT'],
		);
	}

	/**
	 * @param int|array $nodeIds
	 * @param NodeSettingsType[] $settingsTypes
	 * @return Item\Collection\NodeSettingsCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getByNodesAndTypes(int|array $nodeIds, array $settingsTypes = []): Item\Collection\NodeSettingsCollection
	{
		if (is_int($nodeIds))
		{
			$nodeIds = [$nodeIds];
		}

		if (empty($nodeIds))
		{
			return new Item\Collection\NodeSettingsCollection();
		}

		$query = NodeSettingsTable::query()
			->setSelect(['*'])
			->whereIn('NODE_ID', $nodeIds)
		;

		if (!empty($settingsTypes))
		{
			$query->whereIn('SETTINGS_TYPE', array_map(
				static fn(NodeSettingsType $type) => $type->value,
				$settingsTypes
			));
		}

		$entityCollection = $query->fetchAll();
		$itemsCollection = new Item\Collection\NodeSettingsCollection();

		foreach ($entityCollection as $entity)
		{
			// prevent possible invalid setting_type
			if (NodeSettingsType::tryFrom($entity['SETTINGS_TYPE']) !== null)
			{
				$nodeSettings = $this->convertModelArrayToItem($entity);
				$itemsCollection->add($nodeSettings);
			}
		}

		return $itemsCollection;
	}

	/**
	 * Returns node IDs that have a specific setting value stored.
	 *
	 * @return int[]
	 */
	public function getNodeIdsByTypeAndValue(NodeSettingsType $type, string $value): array
	{
		$rows = NodeSettingsTable::query()
			->setSelect(['NODE_ID'])
			->where('SETTINGS_TYPE', $type->value)
			->where('SETTINGS_VALUE', $value)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'NODE_ID'));
	}

	/**
	 * @param Item\NodeSettings $nodeSettings
	 * @return Item\NodeSettings
	 * @throws CreationFailedException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function create(Item\NodeSettings $nodeSettings): Item\NodeSettings
	{
		$nodeSettingsEntity = NodeSettingsTable::getEntity()->createObject();

		$nodeSettingsCreateResult = $nodeSettingsEntity
			->setNodeId($nodeSettings->nodeId)
			->setSettingsType($nodeSettings->settingsType->value)
			->setSettingsValue($nodeSettings->settingsValue)
			->save()
		;

		if (!$nodeSettingsCreateResult->isSuccess())
		{
			throw (new CreationFailedException())
				->setErrors($nodeSettingsCreateResult->getErrorCollection());
		}

		$nodeSettings->id = $nodeSettingsCreateResult->getId();

		$this->fireAiReportsEnabledEventIfApplicable($nodeSettings);

		return $nodeSettings;
	}

	/**
	 * @param Item\Collection\NodeSettingsCollection $nodeSettingsCollection
	 * @return Item\Collection\NodeSettingsCollection
	 * @throws CreationFailedException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function createByCollection(
		Item\Collection\NodeSettingsCollection $nodeSettingsCollection,
	): Item\Collection\NodeSettingsCollection
	{
		$connection = Application::getConnection();
		try
		{
			$connection->startTransaction();
			foreach ($nodeSettingsCollection as $item)
			{
				$this->create($item);
			}
			$connection->commitTransaction();
		}
		catch (\Exception $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}

		return $nodeSettingsCollection;
	}

	/**
	 * Bulk-insert NodeSettings rows in a single SQL statement (no ORM events, no per-row queries).
	 *
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 */
	public function createBatch(Item\Collection\NodeSettingsCollection $nodeSettingsCollection): void
	{
		if ($nodeSettingsCollection->count() === 0)
		{
			return;
		}

		$now = new DateTime();
		$rows = [];
		foreach ($nodeSettingsCollection as $item)
		{
			$rows[] = [
				'NODE_ID' => $item->nodeId,
				'SETTINGS_TYPE' => $item->settingsType->value,
				'SETTINGS_VALUE' => $item->settingsValue,
				'CREATED_AT' => $now,
				'UPDATED_AT' => $now,
			];
		}

		Application::getConnection()->addMulti(NodeSettingsTable::getTableName(), $rows);

		foreach ($nodeSettingsCollection as $item)
		{
			$this->fireAiReportsEnabledEventIfApplicable($item);
		}
	}

	/**
	 * Sends OnAiReportsEnabled for a NodeSettings row that just turned AiReports on.
	 * The check is centralized here so any new write path (single create, bulk createBatch,
	 * inheritFromParent through createBatch) automatically emits the event without callers
	 * needing to remember it.
	 */
	private function fireAiReportsEnabledEventIfApplicable(Item\NodeSettings $nodeSettings): void
	{
		if (
			$nodeSettings->settingsType !== NodeSettingsType::AiReports
			|| $nodeSettings->settingsValue !== NodeSettingsType::BOOLEAN_TRUE
		)
		{
			return;
		}

		Container::getEventSenderService()
			->send(EventName::OnAiReportsEnabled, [
				'nodeId' => $nodeSettings->nodeId,
				'userId' => (int)CurrentUser::get()->getId(),
			])
		;
	}

	/**
	 * Copies inheritable settings ({@see NodeSettingsType::getCasesInheritedFromParent()}) from the
	 * parent node to a freshly created child node. Only types actually stored on the parent are copied;
	 * absent settings stay unset on the child. Assumes the child has no settings yet — performs only
	 * the bulk INSERT, no DELETE.
	 *
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 */
	public function inheritFromParent(int $childNodeId, int $parentNodeId): void
	{
		$parentSettings = $this->getByNodesAndTypes(
			$parentNodeId,
			NodeSettingsType::getCasesInheritedFromParent(),
		);

		if ($parentSettings->count() === 0)
		{
			return;
		}

		$childCollection = new Item\Collection\NodeSettingsCollection();
		foreach ($parentSettings as $setting)
		{
			$childCollection->add(new Item\NodeSettings(
				nodeId: $childNodeId,
				settingsType: $setting->settingsType,
				settingsValue: $setting->settingsValue,
			));
		}

		$this->createBatch($childCollection);
	}

	/**
	 * Bulk-delete NodeSettings rows of a given type for multiple nodes in a single SQL statement.
	 *
	 * @param int[] $nodeIds
	 * @throws DeleteFailedException
	 */
	public function removeByTypeAndNodeIds(array $nodeIds, NodeSettingsType $settingsType): void
	{
		if (empty($nodeIds))
		{
			return;
		}

		try
		{
			NodeSettingsTable::deleteByFilter([
				'=NODE_ID' => $nodeIds,
				'=SETTINGS_TYPE' => $settingsType->value,
			]);
		}
		catch (\Exception)
		{
			throw new DeleteFailedException();
		}
	}

	/**
	 * @param int $nodeId
	 * @param array|NodeSettingsType $settingsType
	 * @param mixed|null $values
	 * @return void
	 * @throws DeleteFailedException
	 */
	public function removeByTypeAndNodeId(int $nodeId, array|NodeSettingsType $settingsType, mixed $values = null): void
	{
		$typesToDelete = is_array($settingsType)
			? array_map(static fn(NodeSettingsType $type) => $type->value, $settingsType)
			: [$settingsType->value]
		;

		if (empty($typesToDelete))
		{
			return;
		}

		try
		{
			$filter = [
				'=NODE_ID' => $nodeId,
				'=SETTINGS_TYPE' => $typesToDelete,
			];

			if (!empty($values))
			{
				$filter['=SETTINGS_VALUE'] = $values;
			}

			NodeSettingsTable::deleteByFilter($filter);
		}
		catch (\Exception)
		{
			throw new DeleteFailedException();
		}
	}
}
