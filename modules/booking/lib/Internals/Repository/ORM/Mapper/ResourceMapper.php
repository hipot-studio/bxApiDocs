<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM\Mapper;

use Bitrix\Booking\Entity\File\File;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceLinkedEntityCollection;
use Bitrix\Booking\Entity\Resource\ResourceSkuCollection;
use Bitrix\Booking\Internals\Model\EO_Resource;
use Bitrix\Booking\Internals\Model\EO_ResourceLinkedEntity_Collection;
use Bitrix\Booking\Internals\Model\EO_ResourceSku_Collection;
use Bitrix\Booking\Internals\Model\EO_ResourceSkuYandex_Collection;
use Bitrix\Booking\Internals\Model\ResourceTable;
use CFile;

class ResourceMapper
{
	public function __construct(
		private readonly ResourceLinkedEntityMapper $linkedEntityMapper = new ResourceLinkedEntityMapper(),
		private readonly ResourceSkuMapper $resourceSkuMapper = new ResourceSkuMapper(),
		private readonly ResourceSkuYandexMapper $resourceSkuYandexMapper = new ResourceSkuYandexMapper(),
	)
	{
	}

	public function convertFromOrm(EO_Resource $ormResource): Resource
	{
		$resource = (new Resource())
			->setId($ormResource->getId())
			->setExternalId($ormResource->getExternalId())
			->setMain($ormResource->getIsMain())
		;

		$ormResourceType = $ormResource->getType();
		if ($ormResourceType)
		{
			$resource->setType((new ResourceTypeMapper())->convertFromOrm($ormResourceType));
		}

		$ormResourceSettings = $ormResource->getSettings();
		if ($ormResourceSettings)
		{
			$resource->setSlotRanges((new ResourceSlotMapper())->convertFromOrm($ormResourceSettings));
		}

		$ormEntities = $ormResource->getEntities();
		if ($ormEntities)
		{
			$this->setEntityCollection($resource, $ormEntities);
		}

		$ormSkus = $ormResource->getSkus();
		if ($ormSkus)
		{
			$this->setSkus($resource, $ormSkus);
		}

		$ormSkusYandex = $ormResource->getSkusYandex();
		if ($ormSkusYandex)
		{
			$this->setSkusYandex($resource, $ormSkusYandex);
		}

		$ormNotificationSettings = $ormResource->getNotificationSettings();
		if ($ormNotificationSettings)
		{
			$resource
				->setIsInfoNotificationOn($ormNotificationSettings->getIsInfoOn())
				->setTemplateTypeInfo($ormNotificationSettings->getTemplateTypeInfo())
				->setInfoNotificationDelay($ormNotificationSettings->getInfoDelay())
				->setIsConfirmationNotificationOn($ormNotificationSettings->getIsConfirmationOn())
				->setTemplateTypeConfirmation($ormNotificationSettings->getTemplateTypeConfirmation())
				->setConfirmationNotificationDelay($ormNotificationSettings->getConfirmationDelay())
				->setConfirmationNotificationRepetitions($ormNotificationSettings->getConfirmationRepetitions())
				->setConfirmationNotificationRepetitionsInterval($ormNotificationSettings->getConfirmationRepetitionsInterval())
				->setConfirmationCounterDelay($ormNotificationSettings->getConfirmationCounterDelay())
				->setIsReminderNotificationOn($ormNotificationSettings->getIsReminderOn())
				->setTemplateTypeReminder($ormNotificationSettings->getTemplateTypeReminder())
				->setReminderNotificationDelay($ormNotificationSettings->getReminderDelay())
				->setIsFeedbackNotificationOn($ormNotificationSettings->getIsFeedbackOn())
				->setTemplateTypeFeedback($ormNotificationSettings->getTemplateTypeFeedback())
				->setIsDelayedNotificationOn($ormNotificationSettings->getIsDelayedOn())
				->setTemplateTypeDelayed($ormNotificationSettings->getTemplateTypeDelayed())
				->setDelayedNotificationDelay($ormNotificationSettings->getDelayedDelay())
				->setDelayedCounterDelay($ormNotificationSettings->getDelayedCounterDelay())
			;
		}

		$ormResourceData = $ormResource->getData();
		if (!$resource->isExternal() && $ormResourceData)
		{
			$resource
				->setName($ormResourceData->getName())
				->setDescription($ormResourceData->getDescription())
				->setCreatedBy($ormResourceData->getCreatedBy())
				->setDeleted($ormResourceData->getIsDeleted())
				->setCreatedAt($ormResourceData->getCreatedAt()->getTimestamp())
				->setUpdatedAt($ormResourceData->getUpdatedAt()->getTimestamp())
				->setDeletedAt($ormResourceData->getDeletedAt()?->getTimestamp())
			;

			if ($ormResourceData->getAvatarId())
			{
				$avatarData = CFile::GetByID($ormResourceData->getAvatarId())->Fetch();

				if (is_array($avatarData))
				{
					$avatar = new File(
						(int)($avatarData['ID'] ?? null),
						(string)($avatarData['SRC'] ?? null)
					);

					$resource->setAvatar($avatar);
				}
			}
		}

		return $resource;
	}

	public function convertToOrm(Resource $resource): EO_Resource
	{
		$ormResource = $resource->getId()
			? EO_Resource::wakeUp($resource->getId())
			: ResourceTable::createObject();

		$type = $resource->getType();
		if ($type)
		{
			$ormResource->setTypeId($type->getId());
		}

		if ($resource->getExternalId())
		{
			$ormResource->setExternalId($resource->getExternalId());
		}

		$ormResource->setIsMain($resource->isMain());

		return $ormResource;
	}

	private function setEntityCollection(
		Resource $resource,
		EO_ResourceLinkedEntity_Collection $ormEntityCollection
	): void
	{
		$linkedEntityCollection = new ResourceLinkedEntityCollection();
		foreach ($ormEntityCollection as $ormResourceLinkedEntity)
		{
			$linkedEntityCollection->add($this->linkedEntityMapper->convertFromOrm($ormResourceLinkedEntity));
		}

		$resource->setEntityCollection($linkedEntityCollection);
	}

	private function setSkus(Resource $resource, EO_ResourceSku_Collection $ormResourceSkuCollection): void
	{
		$resourceSkuCollection = new ResourceSkuCollection();

		foreach ($ormResourceSkuCollection as $ormResourceSku)
		{
			$resourceSkuCollection->add($this->resourceSkuMapper->convertFromOrm($ormResourceSku));
		}

		$resource->setSkuCollection($resourceSkuCollection);
	}

	private function setSkusYandex(Resource $resource, EO_ResourceSkuYandex_Collection $ormResourceSkuCollection): void
	{
		$resourceSkuCollection = new ResourceSkuCollection();

		foreach ($ormResourceSkuCollection as $ormResourceSku)
		{
			$resourceSkuCollection->add($this->resourceSkuYandexMapper->convertFromOrm($ormResourceSku));
		}

		$resource->setSkuYandexCollection($resourceSkuCollection);
	}
}
