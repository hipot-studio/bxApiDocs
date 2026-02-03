<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Mapper;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Models\EO_DocumentSession;
use Bitrix\Disk\Internal\Entity\DocumentSession;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;
use LogicException;

class DocumentSessionMapper
{
	/**
	 * @param EO_DocumentSession $ormModel
	 * @return DocumentSession
	 * @throws ArgumentException
	 */
	public function convertFromOrm(EO_DocumentSession $ormModel): DocumentSession
	{
		$ormModelService = $ormModel->getService();
		$service = DocumentService::tryFrom($ormModelService);

		if (is_null($service))
		{
			throw new LogicException("Invalid value \"$ormModelService\" for service");
		}

		$context = $ormModel->getContext();

		if (is_string($context))
		{
			$context = Json::decode($context);
		}

		return
			(new DocumentSession())
				->setId($ormModel->getId())
				->setService($service)
				->setObjectId($ormModel->getObjectId())
				->setVersionId($ormModel->getVersionId())
				->setUserId($ormModel->getUserId())
				->setOwnerId($ormModel->getOwnerId())
				->setIsExclusive($ormModel->getIsExclusive())
				->setExternalHash($ormModel->getExternalHash())
				->setCreateTime($ormModel->getCreateTime())
				->setType($ormModel->getType())
				->setStatus($ormModel->getStatus())
				->setContext($context)
			;
	}

	/**
	 * @param DocumentSession $entity
	 * @return EO_DocumentSession
	 * @throws ArgumentException
	 */
	public function convertToOrm(DocumentSession $entity): EO_DocumentSession
	{
		$id = $entity->getId();

		if (is_int($id))
		{
			$ormModel = EO_DocumentSession::wakeUp($id);
		}
		else
		{
			$ormModel = DocumentSessionTable::createObject();
		}

		$context = $entity->getContext();

		if (is_array($context))
		{
			$context = Json::encode($context);
		}

		return
			$ormModel
				->setService($entity->getService()->value)
				->setObjectId($entity->getObjectId())
				->setVersionId($entity->getVersionId())
				->setUserId($entity->getUserId())
				->setOwnerId($entity->getOwnerId())
				->setIsExclusive($entity->getIsExclusive())
				->setExternalHash($entity->getExternalHash())
				->setCreateTime($entity->getCreateTime())
				->setType($entity->getType())
				->setStatus($entity->getStatus())
				->setContext($context)
			;
	}
}