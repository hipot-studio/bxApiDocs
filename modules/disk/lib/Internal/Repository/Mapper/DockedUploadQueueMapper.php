<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Mapper;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Model\DockedUploadQueueTable;
use Bitrix\Disk\Internal\Model\EO_DockedUploadQueue;
use Bitrix\Main\Type\DateTime;
use LogicException;

class DockedUploadQueueMapper
{
	public function convertFromOrm(EO_DockedUploadQueue $ormModel): DockedUploadQueue
	{
		$rawStatus = $ormModel->getStatus();
		$status = QueueStatus::tryFrom($rawStatus);
		if ($status === null)
		{
			throw new LogicException("Invalid value \"$rawStatus\" for docked upload queue status");
		}

		return
			(new DockedUploadQueue())
				->setId($ormModel->getId())
				->setUserId($ormModel->getUserId())
				->setStatus($status)
				->setCreateTime($ormModel->getCreateTime())
				->setUpdateTime($ormModel->getUpdateTime())
				->setExpiresAt($ormModel->getExpiresAt())
			;
	}

	public function convertToOrm(DockedUploadQueue $entity): EO_DockedUploadQueue
	{
		$id = $entity->getId();

		$ormModel = is_int($id)
			? EO_DockedUploadQueue::wakeUp($id)
			: DockedUploadQueueTable::createObject()
		;

		$ormModel
			->setUserId($entity->getUserId())
			->setStatus($entity->getStatus()?->value)
			->setExpiresAt($entity->getExpiresAt())
		;

		if ($entity->getCreateTime() !== null)
		{
			$ormModel->setCreateTime($entity->getCreateTime());
		}

		// Always bump UPDATE_TIME on persist so status transitions refresh
		// the sort/heuristic key, regardless of the (possibly stale) value
		// carried by the entity.
		$ormModel->setUpdateTime(new DateTime());

		return $ormModel;
	}
}
