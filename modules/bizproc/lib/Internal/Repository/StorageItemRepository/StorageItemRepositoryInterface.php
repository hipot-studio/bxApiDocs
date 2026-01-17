<?php

namespace Bitrix\Bizproc\Internal\Repository\StorageItemRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Exception\StorageItem\CreateStorageItemException;
use Bitrix\Bizproc\Internal\Exception\StorageItem\DeleteStorageItemException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Type\DateTime;

interface StorageItemRepositoryInterface
{
	/**
	 * @param int $storageTypeId
	 * @param int $itemId
	 * @param array $select
	 * @return Entity\StorageItem\StorageItem|null
	 */
	public function getItem(int $storageTypeId, int $itemId, array $select): ?Entity\StorageItem\StorageItem;

	/**
	 * @param int $id
	 * @return bool
	 */
	public function exists(int $id): bool;

	/**
	 * @param int $storageTypeId
	 * @param array $parameters
	 * @return Entity\StorageItem\StorageItemCollection|null
	 */
	public function getItems(int $storageTypeId, array $parameters = []): ?Entity\StorageItem\StorageItemCollection;

	/**
	 * @param int $storageTypeId
	 * @param int|null $limit
	 * @param int|null $offset
	 * @param FilterInterface|null $filter
	 * @param array|null $sort
	 * @param array|null $select
	 * @return Entity\StorageItem\StorageItemCollection|null
	 */
	public function getList(
		int $storageTypeId,
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): ?Entity\StorageItem\StorageItemCollection;

	/**
	 * @param int $storageTypeId
	 * @param Entity\StorageItem\StorageItem $item
	 * @param string|null $exceptionClass
	 * @return AddResult|UpdateResult
	 * @throws CreateStorageItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function saveItem(int $storageTypeId, Entity\StorageItem\StorageItem $item): AddResult|UpdateResult;

	/**
	 * @param int $itemId
	 * @return void
	 * @throws DeleteStorageItemException
	 */
	public function deleteItem(int $itemId): void;

	/**
	 * @param int $storageTypeId
	 * @param array $filter
	 * @return int
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getCount(int $storageTypeId, array $filter = []): int;

	/**
	 * @param DateTime $createdTime
	 * @param ?int $limit
	 * @return array
	 */
	public function findOldStorageItemIds(DateTime $createdTime, ?int $limit = null): array;

	/**
	 * @param array $ids
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function deleteByIds(array $ids): void;
}
