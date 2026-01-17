<?php

namespace Bitrix\Bizproc\Internal\Repository\StorageTypeRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Exception\StorageType\CreateStorageTypeException;
use Bitrix\Bizproc\Internal\Exception\StorageType\DeleteStorageTypeException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Provider\Params\FilterInterface;

interface StorageTypeRepositoryInterface
{
	/**
	 * @param int $id
	 * @return bool
	 */
	public function exists(int $id): bool;

	/**
	 * @param int|null $limit
	 * @param int|null $offset
	 * @param FilterInterface|null $filter
	 * @param array|null $sort
	 * @param array|null $select
	 * @return Entity\StorageType\StorageTypeCollection
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getList(
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): Entity\StorageType\StorageTypeCollection;

	/**
	 * @return Entity\StorageType\StorageTypeCollection
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getAllForActivity(): Entity\StorageType\StorageTypeCollection;

	/**
	 * @param int $id
	 * @param array $select
	 * @return Entity\StorageType\StorageType|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getById(int $id, array $select): ?Entity\StorageType\StorageType;

	/**
	 * @param array $filter
	 * @param array $select
	 * @return Entity\StorageType\StorageType|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getType(array $filter = [], array $select = []): ?Entity\StorageType\StorageType;

	/**
	 * @param Entity\StorageType\StorageType $storageType
	 * @param string|null $exceptionClass
	 * @return AddResult|UpdateResult
	 * @throws CreateStorageTypeException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function save(
		Entity\StorageType\StorageType $storageType,
		string $exceptionClass = null
	): AddResult|UpdateResult;

	/**
	 * @param int $id
	 * @return void
	 * @throws DeleteStorageTypeException
	 */
	public function delete(int $id): void;
}
