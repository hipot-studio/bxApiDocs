<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM\Mapper;

use Bitrix\Booking\Entity\Resource\ResourceLinkedEntity;
use Bitrix\Booking\Internals\Model\Enum\ResourceLinkedEntityType;
use Bitrix\Booking\Internals\Model\EO_ResourceLinkedEntity;

class ResourceLinkedEntityMapper
{
	public function convertFromOrm(EO_ResourceLinkedEntity $ormResourceLinkedEntity): ResourceLinkedEntity
	{
		return (new ResourceLinkedEntity())
			->setId($ormResourceLinkedEntity->getId())
			->setEntityId($ormResourceLinkedEntity->getEntityId())
			->setEntityType(ResourceLinkedEntityType::from($ormResourceLinkedEntity->getEntityType()))
			->setCreatedAt($ormResourceLinkedEntity->getCreatedAt()->getTimestamp())
		;
	}
}
