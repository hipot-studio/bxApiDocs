<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Snapshot;

use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\StorageTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserAccessTable;

/**
 * The storages whose contents a user reads in full, so that the fetch may drop the rights predicate
 * for them altogether.
 *
 * This is the one place the algorithm lives, and it is one condition of two halves, exactly as the
 * legacy snapshot of the desktop client states it: the user may read the root of the storage, and
 * nothing under that root takes the reading away again. Either half alone is not the answer - a root
 * that is closed now is closed whatever lies below it, and an open root says nothing about a
 * revocation deeper in the tree. The legacy code is bound to the context of one storage and its own
 * constructor, so what is reused is the algorithm and not the object.
 *
 * Broader than the legacy check on purpose. The legacy one looks at the two personal codes of the
 * user; a right revoked through a department or a workgroup would slip past it and the storage would
 * be read without the check when it must not be. Here every code the user answers to is considered,
 * together with the two codes that belong to no list - "any authorised user" and "the creator".
 * Being wrong in this direction costs a slower query; being wrong in the other direction hands over
 * files the user may not see.
 *
 * Both halves are asked afresh every time and no answer of this class is carried anywhere: an answer
 * frozen for the life of a token would let a fetch be read to its end after the access behind it was
 * taken away.
 */
final class SkipRightsProbe
{
	/**
	 * The columns the rights expression is built over. They name the root object of the storage: the
	 * candidate row of the query is the storage, and what is asked about is the object it stands on.
	 */
	private const RIGHTS_COLUMNS = ['ROOT_OBJECT.ID', 'ROOT_OBJECT.CREATED_BY'];

	/**
	 * The second half of the condition. The first one - that the user may read the root at all - is
	 * answered by whoever produced the map, and both have to hold before the predicate may be dropped.
	 *
	 * @param array<int, int> $rootObjectIdByStorageId storage id => root object id, from a source that
	 *        has just established the user may read every one of these roots:
	 *        {@see findReadableRootObjects()} or
	 *        {@see \Bitrix\Disk\Internal\Service\Sync\StorageListProvider::getVisibleStorages()}.
	 *        A map assembled without that answer would drop the predicate for a storage the user has
	 *        already lost.
	 * @return int[] storage ids
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findStoragesWithoutRightsCheck(int $userId, array $rootObjectIdByStorageId): array
	{
		if ($userId <= 0 || $rootObjectIdByStorageId === [])
		{
			return [];
		}

		$rows = RightTable::query()
			// Driven from the rights table rather than from the tree of paths: revocations are rare
			// portal-wide, while the subtrees of the roots are the whole portal.
			->where('NEGATIVE', true)
			->whereIn('ACCESS_CODE', $this->getAccessCodes($userId))
			->whereIn('PATH_CHILD.PARENT_ID', array_values($rootObjectIdByStorageId))
			->addSelect('PATH_CHILD.PARENT_ID', 'ROOT_OBJECT_ID')
			->setGroup(['PATH_CHILD.PARENT_ID'])
			->fetchAll()
		;

		$restrictedRootObjectIds = [];
		foreach ($rows as $row)
		{
			$restrictedRootObjectIds[(int)$row['ROOT_OBJECT_ID']] = true;
		}

		if ($restrictedRootObjectIds === [])
		{
			return array_keys($rootObjectIdByStorageId);
		}

		$skippable = [];
		foreach ($rootObjectIdByStorageId as $storageId => $rootObjectId)
		{
			if (!isset($restrictedRootObjectIds[$rootObjectId]))
			{
				$skippable[] = $storageId;
			}
		}

		return $skippable;
	}

	/**
	 * The roots of the storages of the cycle, and only of those whose root the user may read at this
	 * very moment.
	 *
	 * Which storages the cycle is about is not decided again here: within a cycle the list comes from
	 * the row of the session, and recomputing it would make the fetch read a list the token was never
	 * issued against. Whether the user may still read each of those roots is a different question, and
	 * it is asked on every page. The list outlives the access behind it and access ends without leaving
	 * a negative right behind - being taken out of a workgroup only takes the code of that group out of
	 * the codes of the user - so a storage read without the predicate on the strength of the list alone
	 * would go on handing over the cards of every file in it until the token expired.
	 *
	 * A root that is no longer readable does not put its storage out of the fetch, it only takes the
	 * cheap path away from it. A user may hold a right of their own on an object deep inside a storage
	 * whose root is closed to them - a file shared personally out of a workgroup they have left - and
	 * the predicate is what still hands exactly those over.
	 *
	 * The answer rides on the statement that resolves the roots and costs no query of its own: it is
	 * the root-object flavour of the expression every list of Disk is filtered by, asked once for the
	 * whole batch and never once per storage.
	 *
	 * @param int[] $storageIds
	 * @return array<int, int> storage id => root object id
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findReadableRootObjects(int $userId, array $storageIds): array
	{
		if ($userId <= 0 || $storageIds === [])
		{
			return [];
		}

		$parameters = [
			'select' => ['ID', 'ROOT_OBJECT_ID'],
			'filter' => [
				'@ID' => array_values(array_unique($storageIds)),
				// The root-object flavour of the expression answers about the row itself and never
				// climbs the tree of paths, so every candidate row has to be a storage root.
				'=ROOT_OBJECT.PARENT_ID' => null,
			],
		];

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheckForRootObjects(
			new DiskSecurityContext($userId),
			$parameters,
			self::RIGHTS_COLUMNS,
		);

		$roots = [];
		$cursor = StorageTable::getList($parameters);
		while ($row = $cursor->fetch())
		{
			$roots[(int)$row['ID']] = (int)$row['ROOT_OBJECT_ID'];
		}

		return $roots;
	}

	/**
	 * Every code the user answers to, plus the two that stand for a subject instead of a list.
	 *
	 * @return string[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getAccessCodes(int $userId): array
	{
		$rows = UserAccessTable::query()
			->where('USER_ID', $userId)
			->addSelect('ACCESS_CODE')
			->setGroup(['ACCESS_CODE'])
			->fetchAll()
		;

		$codes = array_map(static fn(array $row): string => (string)$row['ACCESS_CODE'], $rows);
		$codes[] = AccessCodeEnum::AUTHORIZED_USER->value;
		$codes[] = AccessCodeEnum::CREATOR->value;

		return array_values(array_unique($codes));
	}
}
