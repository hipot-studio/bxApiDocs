<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Rest\Internal\Entity\Access\AccessPermission;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Model\AccessPermissionTable;
use Bitrix\Rest\Internal\Repository\Mapper\AccessPermissionMapper;
use Bitrix\Main;
use Bitrix\Rest\Internal\Contract\Repository\AccessPermissionRepositoryInterface;

class AccessPermissionRepository implements AccessPermissionRepositoryInterface
{
	private const ANNUAL_CACHE_TTL = 30758400;

	public function __construct(private AccessPermissionMapper $mapper = new AccessPermissionMapper())
	{

	}

	public function save(EntityInterface $entity): void
	{
		$result = AccessPermissionTable::addInsertIgnore([
			'ENTITY_TYPE' => $entity->getEntityType()->value,
			'ACCESS_CODE' => $entity->getAccessCode(),
			'PERMISSION' => $entity->getPermission()->value,
		]);

		if (!$result->isSuccess())
		{
			throw new Main\DB\SqlQueryException(implode(', ', $result->getErrorMessages()));
		}
	}

	public function deleteByEntityTypeAndPermission(
		EntityType $entityType,
		PermissionType $permission,
	): void
	{
		$list = AccessPermissionTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE', $entityType->value)
			->where('PERMISSION', $permission->value)
			->fetchCollection()
		;

		foreach ($list as $item)
		{
			$item->delete();
		}
	}

	/**
	 * @return string[]
	 */
	public function getAccessCodesByEntityTypeAndPermission(
		EntityType $entityType,
		PermissionType $permission,
	): array
	{
		return array_column(
			AccessPermissionTable::query()
				->setSelect(['ACCESS_CODE'])
				->where('ENTITY_TYPE', $entityType->value)
				->where('PERMISSION', $permission->value)
				->setCacheTtl(self::ANNUAL_CACHE_TTL)
				->fetchAll(),
			'ACCESS_CODE'
		);
	}

	/**
	 * @param string[] $accessCodes
	 * @return AccessPermission[]
	 */
	public function getByEntityTypeAndAccessCodes(EntityType $entityType, array $accessCodes): Main\Entity\EntityCollection
	{
		$rows = AccessPermissionTable::query()
			->setSelect(['ID', 'ENTITY_TYPE', 'ACCESS_CODE', 'PERMISSION'])
			->where('ENTITY_TYPE', $entityType->value)
			->whereIn('ACCESS_CODE', $accessCodes)
			->setCacheTtl(self::ANNUAL_CACHE_TTL)
			->fetchAll()
		;

		return $this->mapper->convertCollectionFromOrm($rows);
	}
}
