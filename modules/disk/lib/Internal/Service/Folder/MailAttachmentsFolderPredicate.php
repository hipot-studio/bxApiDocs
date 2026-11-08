<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Folder;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\SpecificFolder;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

final class MailAttachmentsFolderPredicate
{
	public function isInsideMailAttachmentsTree(int $objectId): bool
	{
		return $this->hasMailAttachmentsAncestor($objectId, null);
	}

	public function isInsideMailAttachmentsTreeOfUser(int $objectId, int $userId): bool
	{
		$storage = Driver::getInstance()->getStorageByUserId($userId);
		if ($storage === null)
		{
			return false;
		}

		return $this->hasMailAttachmentsAncestor($objectId, (int)$storage->getId());
	}

	/**
	 * The closure table keeps a zero depth row (PARENT_ID = OBJECT_ID),
	 * so the object itself is matched by the very same condition as its ancestors.
	 */
	private function hasMailAttachmentsAncestor(int $objectId, ?int $storageId): bool
	{
		if ($objectId <= 0)
		{
			return false;
		}

		$ancestor = new Reference(
			'ANCESTOR',
			ObjectTable::class,
			Join::on('this.PARENT_ID', 'ref.ID'),
		);
		$ancestor->configureJoinType(Join::TYPE_INNER);

		$query = ObjectPathTable::query()
			->registerRuntimeField($ancestor)
			->setSelect(['ID'])
			->where('OBJECT_ID', $objectId)
			->where('ANCESTOR.CODE', SpecificFolder::CODE_FOR_MAIL_ATTACHMENTS)
			->setLimit(1)
		;

		if ($storageId !== null)
		{
			$query->where('ANCESTOR.STORAGE_ID', $storageId);
		}

		return (bool)$query->fetch();
	}
}
