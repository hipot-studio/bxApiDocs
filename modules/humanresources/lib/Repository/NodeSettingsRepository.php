<?php

namespace Bitrix\HumanResources\Repository;

use Bitrix\HumanResources\Exception\CreationFailedException;
use Bitrix\HumanResources\Exception\DeleteFailedException;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Model\NodeSettingsTable;
use Bitrix\HumanResources\Type\NodeSettingsType;
use Bitrix\Main\Application;

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
