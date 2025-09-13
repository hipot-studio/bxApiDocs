<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Entity;
use Bitrix\Booking\Internals\Exception\Resource\CreateResourceException;
use Bitrix\Booking\Internals\Exception\Resource\RemoveResourceException;
use Bitrix\Booking\Internals\Exception\Resource\UpdateResourceException;
use Bitrix\Booking\Internals\Model\ResourceNotificationSettingsTable;
use Bitrix\Booking\Internals\Model\ResourceTable;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ResourceDataMapper;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ResourceMapper;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;
use Bitrix\Booking\Provider\Params\SelectInterface;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

class ResourceRepository implements ResourceRepositoryInterface
{
	private ResourceMapper $mapper;
	private ResourceDataMapper $resourceDataMapper;

	public function __construct(ResourceMapper $mapper, ResourceDataMapper $resourceDataMapper)
	{
		$this->mapper = $mapper;
		$this->resourceDataMapper = $resourceDataMapper;
	}

	public function getList(
		int|null $limit = null,
		int|null $offset = null,
		ConditionTree|null $filter = null,
		array|null $sort = null,
		SelectInterface|null $select = null,
		int|null $userId = null,
	): Entity\Resource\ResourceCollection
	{
		$query = ResourceTable::query()
			->setSelect($select ? $select->prepareSelect() : ['*'])
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		if ($filter !== null)
		{
			$query->where($filter);
		}

		if ($sort !== null)
		{
			$query->setOrder($sort);
		}

		$ormResources = QueryHelper::decompose($query);
		$resources = [];
		foreach ($ormResources as $ormResource)
		{
			$resources[] = $this->mapper->convertFromOrm($ormResource);
		}

		return new Entity\Resource\ResourceCollection(...$resources);
	}

	public function getResourceTypeCount(array $typeIds): array
	{
		if (empty($typeIds))
		{
			return [];
		}

		$result = array_fill_keys($typeIds, 0);

		$rowsList = ResourceTable::query()
			->whereIn('TYPE_ID', $typeIds)
			->setSelect(['TYPE_ID', 'COUNT'])
			->setGroup(['TYPE_ID'])
			->registerRuntimeField(
				'COUNT',
				new ExpressionField('COUNT', 'COUNT(*)')
			)
			->fetchAll()
		;

		foreach ($rowsList as $row)
		{
			$result[(int)$row['TYPE_ID']] = (int)$row['COUNT'];
		}

		return $result;
	}

	public function getTotal(ConditionTree|null $filter = null, int|null $userId = null): int
	{
		$query = ResourceTable::query()
			->setSelect(['COUNT'])
			->registerRuntimeField('COUNT', new ExpressionField('COUNT', 'COUNT(*)'));

		if ($filter !== null)
		{
			$query->where($filter);
		}

		return (int)$query->fetch()['COUNT'];
	}

	public function getById(int $id, int|null $userId = null): Entity\Resource\Resource|null
	{
		return $this->getList(
			limit: 1,
			filter: (new ConditionTree())->where('ID', '=', $id),
			select: new ResourceSelect(),
		)->getFirstCollectionItem();
	}

	public function save(Entity\Resource\Resource $resource): int
	{
		return $resource->getId()
			? $this->update($resource)
			: $this->insert($resource)
		;
	}

	public function remove(int $resourceId): void
	{
		$ormResource = ResourceTable::getByPrimary($resourceId)->fetchObject();
		if (!$ormResource)
		{
			throw new RemoveResourceException('Resource has not been found');
		}

		$ormResourceData = $ormResource->fillData();
		if ($ormResourceData)
		{
			$dataDeleteResult = $ormResourceData->delete();
			if (!$dataDeleteResult->isSuccess())
			{
				throw new RemoveResourceException($dataDeleteResult->getErrors()[0]->getMessage());
			}
		}

		$ormResourceNotificationSettings = $ormResource->fillNotificationSettings();
		if ($ormResourceNotificationSettings)
		{
			$notificationSettingsDeleteResult = $ormResourceNotificationSettings->delete();
			if (!$notificationSettingsDeleteResult->isSuccess())
			{
				throw new RemoveResourceException($notificationSettingsDeleteResult->getErrors()[0]->getMessage());
			}
		}

		$deleteResult = $ormResource->delete();
		if (!$deleteResult->isSuccess())
		{
			throw new RemoveResourceException($deleteResult->getErrors()[0]->getMessage());
		}
	}

	/**
	 * @throws CreateResourceException
	 */
	private function insert(Entity\Resource\Resource $resource): int
	{
		$ormResource = $this->mapper->convertToOrm($resource);
		$resourceSaveResult = $ormResource->save();
		if (!$resourceSaveResult->isSuccess())
		{
			throw new CreateResourceException($resourceSaveResult->getErrors()[0]->getMessage());
		}

		$resource->setId($resourceSaveResult->getId());

		if (!$resource->isExternal())
		{
			$dataSaveResult = $this->resourceDataMapper->convertToOrm($resource)->save();
			if (!$dataSaveResult->isSuccess())
			{
				throw new CreateResourceException($dataSaveResult->getErrors()[0]->getMessage());
			}
		}

		$notificationSettingsSaveResult = ResourceNotificationSettingsTable
			::createObject()
			->setResourceId($resource->getId())
			->setIsInfoOn($resource->isInfoNotificationOn())
			->setTemplateTypeInfo($resource->getTemplateTypeInfo())
			->setInfoDelay($resource->getInfoNotificationDelay())
			->setIsConfirmationOn($resource->isConfirmationNotificationOn())
			->setTemplateTypeConfirmation($resource->getTemplateTypeConfirmation())
			->setConfirmationDelay($resource->getConfirmationNotificationDelay())
			->setConfirmationRepetitions($resource->getConfirmationNotificationRepetitions())
			->setConfirmationRepetitionsInterval($resource->getConfirmationNotificationRepetitionsInterval())
			->setConfirmationCounterDelay($resource->getConfirmationCounterDelay())
			->setIsReminderOn($resource->isReminderNotificationOn())
			->setTemplateTypeReminder($resource->getTemplateTypeReminder())
			->setReminderDelay($resource->getReminderNotificationDelay())
			->setIsFeedbackOn($resource->isFeedbackNotificationOn())
			->setTemplateTypeFeedback($resource->getTemplateTypeFeedback())
			->setIsDelayedOn($resource->isDelayedNotificationOn())
			->setTemplateTypeDelayed($resource->getTemplateTypeDelayed())
			->setDelayedDelay($resource->getDelayedNotificationDelay())
			->setDelayedCounterDelay($resource->getDelayedCounterDelay())
			->save()
		;
		if (!$notificationSettingsSaveResult->isSuccess())
		{
			throw new CreateResourceException($notificationSettingsSaveResult->getErrors()[0]->getMessage());
		}

		return $resourceSaveResult->getId();
	}

	private function update(Entity\Resource\Resource $resource): int
	{
		$ormResource = $this->mapper->convertToOrm($resource);
		$resourceSaveResult = $ormResource->save();
		if (!$resourceSaveResult->isSuccess())
		{
			throw new CreateResourceException($resourceSaveResult->getErrors()[0]->getMessage());
		}

		$dataSaveResult = $this->resourceDataMapper->convertToOrm($resource)->save();
		if (!$dataSaveResult->isSuccess())
		{
			throw new UpdateResourceException($dataSaveResult->getErrors()[0]->getMessage());
		}

		$notificationSettings = $ormResource->fillNotificationSettings();
		if (!$notificationSettings)
		{
			$notificationSettings = ResourceNotificationSettingsTable::createObject();
			$notificationSettings->setResourceId($resource->getId());
		}

		$notificationSettingsSaveResult = $notificationSettings
			->setIsInfoOn($resource->isInfoNotificationOn())
			->setTemplateTypeInfo($resource->getTemplateTypeInfo())
			->setInfoDelay($resource->getInfoNotificationDelay())
			->setIsConfirmationOn($resource->isConfirmationNotificationOn())
			->setTemplateTypeConfirmation($resource->getTemplateTypeConfirmation())
			->setConfirmationDelay($resource->getConfirmationNotificationDelay())
			->setConfirmationRepetitions($resource->getConfirmationNotificationRepetitions())
			->setConfirmationRepetitionsInterval($resource->getConfirmationNotificationRepetitionsInterval())
			->setConfirmationCounterDelay($resource->getConfirmationCounterDelay())
			->setIsReminderOn($resource->isReminderNotificationOn())
			->setTemplateTypeReminder($resource->getTemplateTypeReminder())
			->setReminderDelay($resource->getReminderNotificationDelay())
			->setIsFeedbackOn($resource->isFeedbackNotificationOn())
			->setTemplateTypeFeedback($resource->getTemplateTypeFeedback())
			->setIsDelayedOn($resource->isDelayedNotificationOn())
			->setTemplateTypeDelayed($resource->getTemplateTypeDelayed())
			->setDelayedDelay($resource->getDelayedNotificationDelay())
			->setDelayedCounterDelay($resource->getDelayedCounterDelay())
			->save()
		;
		if (!$notificationSettingsSaveResult->isSuccess())
		{
			throw new UpdateResourceException($notificationSettingsSaveResult->getErrors()[0]->getMessage());
		}

		return $resource->getId();
	}
}
