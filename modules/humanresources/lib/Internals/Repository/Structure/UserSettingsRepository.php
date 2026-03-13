<?php

namespace Bitrix\HumanResources\Internals\Repository\Structure;

use Bitrix\HumanResources\Exception\CreationFailedException;
use Bitrix\HumanResources\Exception\DeleteFailedException;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Model\UserSettingsTable;
use Bitrix\HumanResources\Type\UserSettingsType;
use Bitrix\Main\Application;

class UserSettingsRepository
{
	/**
	 * @param array $userSettings
	 * @return Item\UserSettings
	 */
	protected function convertModelArrayToItem(array $userSettings): Item\UserSettings
	{
		return new Item\UserSettings(
			userId: $userSettings['USER_ID'],
			settingsType: UserSettingsType::tryFrom($userSettings['SETTINGS_TYPE']),
			settingsValue: $userSettings['SETTINGS_VALUE'],
			id: $userSettings['ID'],
			createdAt: $userSettings['CREATED_AT'],
			updatedAt: $userSettings['UPDATED_AT'],
		);
	}

	/**
	 * @param int $userId
	 * @param UserSettingsType[] $settingsTypes
	 * @return Item\Collection\UserSettingsCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getByUserAndTypes(int $userId, array $settingsTypes = []): Item\Collection\UserSettingsCollection
	{
		$query = UserSettingsTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
		;

		if (!empty($settingsTypes))
		{
			$query->whereIn('SETTINGS_TYPE', array_map(
				static fn(UserSettingsType $type) => $type->value,
				$settingsTypes
			));
		}

		$entityCollection = $query->fetchAll();
		$itemsCollection = new Item\Collection\UserSettingsCollection();

		foreach ($entityCollection as $entity)
		{
			if (UserSettingsType::tryFrom($entity['SETTINGS_TYPE']))
			{
				$userSettings = $this->convertModelArrayToItem($entity);
				$itemsCollection->add($userSettings);
			}
		}

		return $itemsCollection;
	}

	/**
	 * @param Item\UserSettings $userSettings
	 * @return Item\UserSettings
	 * @throws CreationFailedException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function create(Item\UserSettings $userSettings): Item\UserSettings
	{
		$userSettingsEntity = UserSettingsTable::getEntity()->createObject();

		$userSettingsCreateResult = $userSettingsEntity
			->setUserId($userSettings->userId)
			->setSettingsType($userSettings->settingsType->value)
			->setSettingsValue($userSettings->settingsValue)
			->save()
		;

		if (!$userSettingsCreateResult->isSuccess())
		{
			throw (new CreationFailedException())
				->setErrors($userSettingsCreateResult->getErrorCollection());
		}

		$userSettings->id = $userSettingsCreateResult->getId();

		return $userSettings;
	}

	/**
	 * @param Item\Collection\UserSettingsCollection $userSettingsCollection
	 * @return Item\Collection\UserSettingsCollection
	 * @throws CreationFailedException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function createByCollection(
		Item\Collection\UserSettingsCollection $userSettingsCollection,
	): Item\Collection\UserSettingsCollection
	{
		$connection = Application::getConnection();
		try
		{
			$connection->startTransaction();
			foreach ($userSettingsCollection as $item)
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

		return $userSettingsCollection;
	}

	/**
	 * @param int $userId
	 * @param UserSettingsType $settingsType
	 * @return void
	 * @throws DeleteFailedException
	 */
	public function removeByTypeAndUserId(int $userId, UserSettingsType $settingsType): void
	{
		try
		{
			UserSettingsTable::deleteByFilter([
				'=USER_ID' => $userId,
				'@SETTINGS_TYPE' => $settingsType->value,
			]);
		}
		catch (\Exception)
		{
			throw new DeleteFailedException();
		}
	}

	public function removeByUserIdAndNodeId(int $userId, int $nodeId): void
	{
		try
		{
			UserSettingsTable::deleteByFilter([
				'=USER_ID' => $userId,
				'=SETTINGS_VALUE' => $nodeId,
			]);
		}
		catch (\Exception)
		{
			throw new DeleteFailedException();
		}
	}
}
