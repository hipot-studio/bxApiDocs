<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\StorageTable;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

/**
 * The storages a user may see, read in one statement.
 *
 * This is the unit the whole contour works in: the initial fetch reads storage by storage, and the
 * collector of places looks for links only inside these storages. Everything downstream therefore
 * inherits the visibility decided here - a storage that leaks into this list leaks the structure of
 * the portal, and one missing from it makes its files invisible to synchronisation for the whole
 * cycle of the session.
 *
 * Visibility is decided by the module's own list expression rather than by asking about each
 * storage in turn: an answer per storage would be a query per storage, and the same predicate is
 * what every large list of Disk is already filtered by.
 */
class StorageListProvider
{
	/**
	 * The kinds of storage the contour synchronises: the personal storage of a user, the common
	 * storage of the portal and the storages of workgroups. The storages other modules keep in Disk
	 * (chat files, mail attachments, generated documents) are service storages of those modules,
	 * not a place a user keeps files in, and are left out of the client's copy.
	 *
	 * The list is also what keeps an unknown storage kind from reaching the code at all: the kind
	 * is a class name stored in a column, and building a storage of a kind whose class is absent
	 * would end the request with a fatal error rather than with an answer. Matching the column
	 * against the kinds we serve is done in the query, so an unknown one is never instantiated.
	 *
	 * The checkpoints of storages of other people are selected by the same list ({@see
	 * CheckpointListProvider}): a narrowing kept in one of the two places only would let the service
	 * storages of neighbouring modules back into the fetch through the other.
	 */
	public const SYNCHRONISED_ENTITY_TYPES = [
		ProxyType\Common::class,
		ProxyType\User::class,
		ProxyType\Group::class,
	];

	/**
	 * @return array<int, int> storage id => id of its root folder, ordered by storage id
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getVisibleStorages(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$parameters = [
			'select' => ['ID', 'ROOT_OBJECT_ID'],
			'filter' => [
				'=MODULE_ID' => Driver::INTERNAL_MODULE_ID,
				'@ENTITY_TYPE' => self::SYNCHRONISED_ENTITY_TYPES,
				'=ROOT_OBJECT.PARENT_ID' => null,
			],
			'order' => ['ID' => 'ASC'],
		];

		// The root-object flavour of the check, not the general one: every candidate row here is a
		// storage root, and the general expression would climb the tree of paths for nothing.
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheckForRootObjects(
			new DiskSecurityContext($userId),
			$parameters,
			['ROOT_OBJECT.ID', 'ROOT_OBJECT.CREATED_BY'],
		);

		$storages = [];
		$rows = StorageTable::getList($parameters);
		while ($row = $rows->fetch())
		{
			$storages[(int)$row['ID']] = (int)$row['ROOT_OBJECT_ID'];
		}

		return $storages;
	}
}
