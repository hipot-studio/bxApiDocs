<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Mapper;

use Bitrix\Disk\Document\Models\EO_RestrictionLog;
use Bitrix\Disk\Document\Models\RestrictionLogTable;
use Bitrix\Disk\Internal\Entity\DocumentRestrictionLog;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;

class DocumentRestrictionLogMapper
{
	/**
	 * @param EO_RestrictionLog $ormModel
	 * @return DocumentRestrictionLog
	 */
	public function convertFromOrm(EO_RestrictionLog $ormModel): DocumentRestrictionLog
	{
		return
			(new DocumentRestrictionLog())
				->setId($ormModel->getId())
				->setService($ormModel->getService())
				->setUserId($ormModel->getUserId())
				->setExternalHash($ormModel->getExternalHash())
				->setStatus($ormModel->getStatus())
				->setCreateTime($ormModel->getCreateTime())
				->setUpdateTime($ormModel->getUpdateTime())
			;
	}

	/**
	 * @param DocumentRestrictionLog $entity
	 * @return EO_RestrictionLog
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function convertToOrm(DocumentRestrictionLog $entity): EO_RestrictionLog
	{
		$id = $entity->getId();

		if (is_int($id))
		{
			$ormModel = EO_RestrictionLog::wakeUp($id);
		}
		else
		{
			$ormModel = RestrictionLogTable::createObject();
		}

		return
			$ormModel
				->setService($entity->getService())
				->setUserId($entity->getUserId())
				->setExternalHash($entity->getExternalHash())
				->setStatus($entity->getStatus())
				->setCreateTime($entity->getCreateTime())
				->setUpdateTime($entity->getUpdateTime())
			;
	}
}