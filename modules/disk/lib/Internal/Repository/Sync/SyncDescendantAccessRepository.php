<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Sync;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Service\Sync\Journal\DescendantAccessSource;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\RightsManager;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;

/**
 * Own readable rights of the descendants of a folder, read in one statement over the closure of its path.
 *
 * Links are left out: a link carries no rights of the object it points to. The rights of the domain of an
 * accepted sharing of a folder are left out too - the recipient holds that folder through a link of their
 * own, and the loss of it reaches them as a change of access rather than as a tombstone.
 */
final class SyncDescendantAccessRepository implements DescendantAccessSource
{
	/**
	 * @return list<array{objectId: int, isFolder: bool, accessCode: string, createdBy: int}>
	 * @throws SqlQueryException
	 */
	public function ownReadableAccess(int $rootObjectId): array
	{
		$readableTaskIds = array_map('intval', Driver::getInstance()->getRightsManager()->getReadableTaskIds());
		if ($rootObjectId <= 0 || $readableTaskIds === [])
		{
			return [];
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$tableObjectPath = $sqlHelper->quote(ObjectPathTable::getTableName());
		$tableObject = $sqlHelper->quote(ObjectTable::getTableName());
		$tableRight = $sqlHelper->quote(RightTable::getTableName());
		$tableSharing = $sqlHelper->quote(SharingTable::getTableName());
		$readableTaskIdsSql = implode(', ', $readableTaskIds);
		$typeFolder = (int)ObjectTable::TYPE_FOLDER;
		$statusApproved = (int)SharingTable::STATUS_IS_APPROVED;
		$sharingDomain = $sqlHelper->getConcatFunction(
			$sqlHelper->convertToDbString(RightsManager::DOMAIN_SHARING . '-'),
			'linked_sharing.ID',
		);

		$sql = <<<SQL
			SELECT descendant.ID, descendant.TYPE, descendant.CREATED_BY, own_right.ACCESS_CODE
			FROM {$tableObjectPath} path
				INNER JOIN {$tableObject} descendant
					ON descendant.ID = path.OBJECT_ID
					AND descendant.ID = descendant.REAL_OBJECT_ID
				INNER JOIN {$tableRight} own_right
					ON own_right.OBJECT_ID = descendant.ID
			WHERE path.PARENT_ID = {$rootObjectId}
				AND path.OBJECT_ID <> {$rootObjectId}
				AND own_right.NEGATIVE = 0
				AND own_right.TASK_ID IN ({$readableTaskIdsSql})
				AND NOT EXISTS (
					SELECT 1
					FROM {$tableSharing} linked_sharing
					WHERE linked_sharing.REAL_OBJECT_ID = descendant.ID
						AND descendant.TYPE = {$typeFolder}
						AND linked_sharing.STATUS = {$statusApproved}
						AND linked_sharing.LINK_OBJECT_ID IS NOT NULL
						AND linked_sharing.LINK_OBJECT_ID > 0
						AND own_right.DOMAIN = {$sharingDomain}
				)
			SQL;

		$rows = [];
		foreach ($connection->query($sql)->fetchAll() as $row)
		{
			$rows[] = [
				'objectId' => (int)$row['ID'],
				'isFolder' => (int)$row['TYPE'] === $typeFolder,
				'accessCode' => (string)$row['ACCESS_CODE'],
				'createdBy' => (int)$row['CREATED_BY'],
			];
		}

		return $rows;
	}
}
