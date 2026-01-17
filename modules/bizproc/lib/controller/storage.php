<?php

namespace Bitrix\Bizproc\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Provider\Params;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Internal\Entity\StorageType;
use Bitrix\Bizproc\Internal\Entity\StorageItem;
use Bitrix\Bizproc\Internal\Entity\StorageField;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Bizproc\Public\Command;
use Bitrix\Bizproc\Public\Service\StorageField\FieldService;

class Storage extends Base
{
	public function listAction(
		PageNavigation $navigation,
		array $filter = [],
		array $sort = [],
		array $select = [],
	): ?StorageType\StorageTypeCollection
	{
		if (!$this->checkAdminAccess())
		{
			return null;
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
		if (!$this->checkAdminAccess())
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
		if (!$this->checkAdminAccess())
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
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		$provider = new StorageTypeProvider();

		if (empty($storageType['id']))
		{
			$this->addError(ErrorBuilder::build('Storage type identifier is not specified.'));

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
		if (!$this->checkAdminAccess())
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

	public function getItemsAction(
		int $storageTypeId,
		PageNavigation $navigation,
		array $filter = [],
		array $sort = [],
		array $select = ['*'],
	): ?StorageItem\StorageItemCollection
	{
		if (!$this->checkAdminAccess())
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
		if (!$this->checkAdminAccess())
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
		if (!$this->checkAdminAccess())
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
		if (!$this->checkAdminAccess())
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

		try
		{
			$storageTypeEntity = StorageItem\StorageItem::mapFromArray(
				[...$entity->toArray(), ...$storageItem],
				$storageTypeId,
			);
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
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		$command = new Command\StorageItem\DeleteStorageItemCommand($storageTypeId, $id);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function getFieldsAction(
		PageNavigation $navigation,
		array $filter = [],
		array $sort = [],
		array $select = ['*'],
	): ?StorageField\StorageFieldCollection
	{
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		$provider = new StorageFieldProvider();

		return $provider->getList(new GridParams(
			pager: Pager::buildFromPageNavigation($navigation),
			filter: new Params\StorageField\StorageFieldFilter($filter),
			sort: new Params\StorageField\StorageFieldSort($sort),
			select: new Params\StorageField\StorageFieldSelect($select),
		));
	}

	public function getFieldAction(int $id): ?StorageField\StorageField
	{
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		try
		{
			return (new StorageFieldProvider())->getById($id);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getFieldsByStorageIdAction(
		int $storageId,
		array $select = ['*'],
		bool $format = false
	): StorageField\StorageFieldCollection|array|null
	{
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		try
		{
			$fieldCollection = (new StorageFieldProvider())->getByStorageId($storageId, $select);

			if ($format)
			{
				$result = [];
				foreach ($fieldCollection as $field)
				{
					$result[] = $field->toProperty();
				}

				return $result;
			}

			return $fieldCollection;
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function addFieldAction(array $field, bool $format = false): StorageField\StorageField|array|null
	{
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		try
		{
			$storageFieldEntity = StorageField\StorageField::mapFromArray($field);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}

		$addStorageFieldCommand = new Command\StorageField\AddStorageFieldCommand(
			storageField: $storageFieldEntity,
		);

		$result = $addStorageFieldCommand->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		if ($format)
		{
			return $result->getStorageField()?->toProperty();
		}

		/** @var Command\StorageField\StorageFieldResult $result */
		return $result->getStorageField();
	}

	public function updateFieldAction(array $field, bool $format = false): StorageField\StorageField|array|null
	{
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		try
		{
			$storageFieldEntity = StorageField\StorageField::mapFromArray($field);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}

		$updateStorageFieldCommand = new Command\StorageField\UpdateStorageFieldCommand(
			storageField: $storageFieldEntity,
		);

		$result = $updateStorageFieldCommand->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		if ($format)
		{
			return $result->getStorageField()?->toProperty();
		}

		/** @var Command\StorageField\StorageFieldResult $result */
		return $result->getStorageField();
	}

	public function deleteFieldAction(int $id): ?array
	{
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		$command = new Command\StorageField\DeleteStorageFieldCommand($id);

		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	protected function checkAdminAccess(): bool
	{
		return (new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
	}

	public function getPreparedFormAction(array $field): ?StorageField\StorageField
	{
		if (!$this->checkAdminAccess())
		{
			return null;
		}

		try
		{
			$fieldService = new FieldService();
			$storageFieldEntity = $fieldService->prepare($field);
			if (!$storageFieldEntity)
			{
				$this->addError(ErrorMessage::GET_DATA_ERROR->getError());

				return null;
			}
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}

		return $storageFieldEntity;
	}
}
