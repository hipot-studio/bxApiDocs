<?php

namespace Bitrix\Bizproc\Internal\Repository\StorageFieldRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Exception\StorageField\CreateStorageFieldException;
use Bitrix\Bizproc\Internal\Exception\StorageField\DeleteStorageFieldException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Provider\Params\FilterInterface;

interface StorageFieldRepositoryInterface
{
	/**
	 * @param int $id
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function exists(int $id): bool;

	/**
	 * @param int|null $limit
	 * @param int|null $offset
	 * @param FilterInterface|null $filter
	 * @param array|null $sort
	 * @param array|null $select
	 * @return Entity\StorageField\StorageFieldCollection
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
	): Entity\StorageField\StorageFieldCollection;

	/**
	 * @param array $filter
	 * @param array $cache
	 * @return int
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getCount(array $filter = [], array $cache = []): int;

	/**
	 * @param int $id
	 * @param array $select
	 * @return Entity\StorageField\StorageField|null
	 */
	public function getById(int $id, array $select): ?Entity\StorageField\StorageField;

	/**
	 * @param int $storageId
	 * @param array $select
	 * @return Entity\StorageField\StorageFieldCollection
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
 */
	public function getByStorageId(int $storageId, array $select): Entity\StorageField\StorageFieldCollection;

	/**
	 * @param Entity\StorageField\StorageField $storageField
	 * @param string|null $exceptionClass
	 * @return AddResult|UpdateResult
	 * @throws CreateStorageFieldException
	 */
	public function save(
		Entity\StorageField\StorageField $storageField,
		string $exceptionClass = null
	): AddResult|UpdateResult;

	/**
	 * @param int $id
	 * @return void
	 * @throws DeleteStorageFieldException
	 */
	public function delete(int $id): void;

	/**
	 * @param int $storageId
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function deleteByStorageId(int $storageId): void;
}
