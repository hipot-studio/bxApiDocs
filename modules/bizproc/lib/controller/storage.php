<?php

namespace Bitrix\Bizproc\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\BaseType\File;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Public\Provider\Params;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Internal\Entity\StorageType;
use Bitrix\Bizproc\Internal\Entity\StorageItem;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Command;

class Storage extends Base
{
	public function listAction(
		PageNavigation $navigation,
		array $filter = [],
		array $sort = [],
		array $select = [],
	): ?StorageType\StorageTypeCollection
	{
		if (!$this->checkStorageAccess())
		{
			return null;
		}

		$closedIds = (new TemplateAccessService())->getClosedStorageTypeIds();
		if ($closedIds !== [])
		{
			$filter['!ID'] = array_merge((array)($filter['!ID'] ?? []), $closedIds);
		}

		$provider = new StorageTypeProvider();

		return $provider->getList(new GridParams(
			pager: Pager::buildFromPageNavigation($navigation),
			filter: new Params\StorageType\StorageTypeFilter($filter),
			sort: new Params\StorageType\StorageTypeSort($sort),
			select: new Params\StorageType\StorageTypeSelect($select),
		));
	}

	public function getAction(int $id): ?StorageType\StorageType
	{
		if (!$this->checkStorageAccess() || !$this->checkStorageTypeAccess($id))
		{
			return null;
		}

		try
		{
			return (new StorageTypeProvider())->getById($id);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function addAction(array $storageType): ?StorageType\StorageType
	{
		if (!$this->checkStorageAccess())
		{
			return null;
		}

		try
		{
			$storageTypeEntity = StorageType\StorageType::mapFromArray($storageType);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}

		$addStorageTypeCommand = new Command\StorageType\AddStorageTypeCommand(
			createdBy: (int)CurrentUser::get()->getId(),
			storageType: $storageTypeEntity,
		);

		$result = $addStorageTypeCommand->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		/** @var Command\StorageType\StorageTypeResult $result */
		return $result->getStorageType();
	}

	public function updateAction(array $storageType): ?StorageType\StorageType
	{
		if (!$this->checkStorageAccess())
		{
			return null;
		}

		$provider = new StorageTypeProvider();

		if (empty($storageType['id']))
		{
			$this->addError(ErrorBuilder::build('Storage type identifier is not specified.'));

			return null;
		}

		if (!$this->checkStorageTypeAccess((int)$storageType['id']))
		{
			return null;
		}

		$entity = $provider->getById((int)$storageType['id']);
		if (!$entity)
		{
			$this->addError(ErrorBuilder::build('Storage type has not been found.'));

			return null;
		}

		try
		{
			$storageTypeEntity = StorageType\StorageType::mapFromArray([...$entity->toArray(), ...$storageType]);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}

		$command = new Command\StorageType\UpdateStorageTypeCommand(
			updatedBy: (int)CurrentUser::get()->getId(),
			storageType: $storageTypeEntity,
		);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$updatedStorageType = $result->getStorageType();
		if ($updatedStorageType)
		{
			$updatedStorageType
				->setCreatedAt($entity->getCreatedAt())
				->setCreatedBy($entity->getCreatedBy());
		}

		return $result->getStorageType();
	}

	public function deleteAction(int $id): ?array
	{
		if (!$this->checkStorageAccess() || !$this->checkStorageTypeAccess($id))
		{
			return null;
		}

		$command = new Command\StorageType\DeleteStorageTypeCommand($id);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function deleteListAction(array $ids): ?array
	{
		if (!$this->checkStorageAccess())
		{
			return null;
		}

		Collection::normalizeArrayValuesByInt($ids, false);
		$normalizedIds = $ids;

		if (empty($normalizedIds))
		{
			$this->addError(ErrorBuilder::build('Storage type identifiers are not specified.'));

			return null;
		}

		if (!$this->checkStorageTypesAccess($normalizedIds))
		{
			return null;
		}

		foreach ($normalizedIds as $id)
		{
			$result = (new Command\StorageType\DeleteStorageTypeCommand($id))->run();
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());
			}
		}

		return [];
	}

	public function getItemsAction(
		int $storageTypeId,
		PageNavigation $navigation,
		array $filter = [],
		array $sort = [],
		array $select = ['*'],
	): ?StorageItem\StorageItemCollection
	{
		if (!$this->checkStorageAccess() || !$this->checkStorageTypeAccess($storageTypeId))
		{
			return null;
		}

		$provider = new StorageItemProvider($storageTypeId);

		return $provider->getList(new GridParams(
			pager: Pager::buildFromPageNavigation($navigation),
			filter: new Params\StorageItem\StorageItemFilter($filter),
			sort: new Params\StorageItem\StorageItemSort($sort),
			select: new Params\StorageItem\StorageItemSelect($select),
		));
	}

	public function getItemAction(int $storageTypeId, int $id): ?StorageItem\StorageItem
	{
		if (!$this->checkStorageAccess() || !$this->checkStorageTypeAccess($storageTypeId))
		{
			return null;
		}

		try
		{
			return (new StorageItemProvider($storageTypeId))->getById($id);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function addItemAction(int $storageTypeId, array $storageItem): ?StorageItem\StorageItem
	{
		if (
			!$this->checkStorageAccess()
			|| !$this->checkStorageTypeAccess($storageTypeId)
			|| !$this->checkFileValuesUnchanged($storageTypeId, $storageItem)
		)
		{
			return null;
		}

		try
		{
			$storageItemEntity = StorageItem\StorageItem::mapFromArray($storageItem, $storageTypeId);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}

		$addStorageTypeCommand = new Command\StorageItem\AddStorageItemCommand(
			createdBy: (int)CurrentUser::get()->getId(),
			storageTypeId: $storageTypeId,
			storageItem: $storageItemEntity,
		);

		$result = $addStorageTypeCommand->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		/** @var Command\StorageItem\StorageItemResult $result */
		return $result->getStorageItem();
	}

	public function updateItemAction(int $storageTypeId, array $storageItem): ?StorageItem\StorageItem
	{
		if (!$this->checkStorageAccess() || !$this->checkStorageTypeAccess($storageTypeId))
		{
			return null;
		}

		$provider = new StorageItemProvider($storageTypeId);

		if (empty($storageItem['id']))
		{
			$this->addError(ErrorBuilder::build('Storage item identifier is not specified.'));

			return null;
		}

		$entity = $provider->getById((int)$storageItem['id']);
		if (!$entity)
		{
			$this->addError(ErrorBuilder::build('Storage item has not been found.'));

			return null;
		}

		if (!$this->checkFileValuesUnchanged($storageTypeId, $storageItem, $entity))
		{
			return null;
		}

		try
		{
			$storageTypeEntity = StorageItem\StorageItem::mapFromArray(
				[...$entity->toArray(), ...$storageItem],
				$storageTypeId,
			)->setStorageId($storageTypeId);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}

		$command = new Command\StorageItem\UpdateStorageItemCommand(
			updatedBy: (int)CurrentUser::get()->getId(),
			storageTypeId: $storageTypeId,
			storageItem: $storageTypeEntity,
		);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$updatedStorageType = $result->getStorageItem();
		if ($updatedStorageType)
		{
			$updatedStorageType
				->setCreatedAt($entity->getCreatedAt())
				->setCreatedBy($entity->getCreatedBy());
		}

		return $result->getStorageItem();
	}

	public function deleteItemAction(int $storageTypeId, int $id): ?array
	{
		return $this->deleteItemsAction([$id]);
	}

	public function deleteItemsAction(array $ids): ?array
	{
		if (!$this->checkStorageAccess())
		{
			return null;
		}

		if (!$ids)
		{
			$this->addError(ErrorBuilder::build('Storage item identifiers are not specified.'));

			return null;
		}

		$repository = Container::getStorageItemRepository();
		if ($repository === null)
		{
			$this->addError(ErrorBuilder::build('Storage item repository is not available.'));

			return null;
		}

		if (!$this->checkStorageTypesAccess($repository->getStorageTypeIdsByItemIds($ids)))
		{
			return null;
		}

		$command = new Command\StorageItem\DeleteStorageItemCommand($ids);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	protected function checkStorageAccess(): bool
	{
		return (new TemplateAccessService())->canAccessTemplates();
	}

	/**
	 * A bare file id sent here is not checked against the rights of the user,
	 * while the grid of items renders it as a download link.
	 */
	private function checkFileValuesUnchanged(
		int $storageTypeId,
		array $storageItem,
		?StorageItem\StorageItem $storedItem = null,
	): bool
	{
		foreach ((new StorageFieldProvider())->getByStorageId($storageTypeId) as $field)
		{
			$code = (string)$field->getCode();
			if ($field->getType() !== FieldType::FILE || !array_key_exists($code, $storageItem))
			{
				continue;
			}

			$value = $storageItem[$code];
			if (\CBPHelper::isEmptyValue($value) || $this->sameFileIds($value, $storedItem?->getValueField($code)))
			{
				continue;
			}

			$this->addError(ErrorMessage::INVALID_PARAM_ARG->getError([
				'#PARAM#' => $code,
				'#VALUE#' => is_scalar($value) ? (string)$value : '',
			]));

			return false;
		}

		return true;
	}

	private function sameFileIds(mixed $value, mixed $storedValue): bool
	{
		$ids = File::toIdList($value);

		return $ids !== null && $ids === array_map('strval', \CBPHelper::flatten($storedValue));
	}

	protected function checkStorageTypeAccess(int $storageTypeId): bool
	{
		if ((new TemplateAccessService())->canEditStorage($storageTypeId))
		{
			return true;
		}

		$this->addError(ErrorMessage::ACCESS_DENIED->getError());

		return false;
	}

	/**
	 * @param int[] $storageTypeIds
	 */
	protected function checkStorageTypesAccess(array $storageTypeIds): bool
	{
		if (!$storageTypeIds)
		{
			return true;
		}

		$closedIds = (new TemplateAccessService())->getClosedStorageTypeIds();
		if (array_intersect(array_map('intval', $storageTypeIds), $closedIds))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return false;
		}

		return true;
	}
}
