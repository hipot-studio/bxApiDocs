<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Internal\Service\StorageActivity\StorageActivityService;
use Bitrix\Bizproc\Public\Command\StorageField\StorageFieldDto;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Bizproc\Public\Service\StorageField\FieldService;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Bizproc\Public\Provider\Params;
use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Public\Command;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\UI\PageNavigation;

class StorageField extends Controller
{
	protected function processBeforeAction(\Bitrix\Main\Engine\Action $action): bool
	{
		if (!$this->checkStorageAccess())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return false;
		}

		return parent::processBeforeAction($action);
	}

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				StorageFieldDto::class,
				'storageFieldDto',
				function (
					$className,
					?int $id = null,
					?string $code = null,
					?int $storageId = null,
					?string $type = null,
					?string $multiple = null,
					?string $mandatory = null,
					?string $name = null,
					?int $sort = null,
					?string $description = null,
					bool $format = false,
				) {
					return new StorageFieldDto(
						id: $id,
						code: $code,
						storageId: $storageId,
						type: $type,
						multiple: $multiple ?? 'N',
						mandatory: $mandatory ?? 'N',
						name: $name,
						sort: $sort ?? 500,
						description: $description ?? '',
						format: $format,
					);
				},
			),
		];
	}

	private function checkStorageAccess(): bool
	{
		return (new TemplateAccessService())->canAccessTemplates();
	}

	private function checkStorageTypeAccess(?int $storageTypeId): bool
	{
		if ((new TemplateAccessService())->canEditStorage((int)$storageTypeId))
		{
			return true;
		}

		$this->addError(ErrorMessage::ACCESS_DENIED->getError());

		return false;
	}

	private function checkFieldType(?string $type, ?string $currentType = null): bool
	{
		if ($type === $currentType || FieldService::isEditableType($type))
		{
			return true;
		}

		$this->addError(ErrorMessage::INVALID_PARAM_ARG->getError(['#PARAM#' => 'type', '#VALUE#' => (string)$type]));

		return false;
	}

	private function checkFieldAccess(int $fieldId): bool
	{
		return $this->checkStorageTypeAccess($this->storageTypeIdOfField($fieldId));
	}

	private function storageTypeIdOfField(int $fieldId): ?int
	{
		if ($fieldId <= 0)
		{
			return null;
		}

		try
		{
			return (new StorageFieldProvider())->getById($fieldId)?->getStorageId();
		}
		catch (Exception)
		{
			return null;
		}
	}

	public function getPreparedFormAction(StorageFieldDto $storageFieldDto): ?Entity\StorageField\StorageField
	{
		if (!$this->checkStorageTypeAccess($storageFieldDto->storageId))
		{
			return null;
		}

		try
		{
			$fieldService = new FieldService();
			$storageFieldEntity = $fieldService->prepare($storageFieldDto);
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

	public function getListAction(
		PageNavigation $navigation,
		array $filter = [],
		array $sort = [],
		array $select = ['*'],
	): ?Entity\StorageField\StorageFieldCollection
	{
		if (isset($filter['STORAGE_ID']))
		{
			if (!$this->checkStorageTypeAccess((int)$filter['STORAGE_ID']))
			{
				return null;
			}
		}
		else
		{
			$closedIds = (new TemplateAccessService())->getClosedStorageTypeIds();
			if ($closedIds !== [])
			{
				$filter['!STORAGE_ID'] = array_merge((array)($filter['!STORAGE_ID'] ?? []), $closedIds);
			}
		}

		$provider = new StorageFieldProvider();

		return $provider->getList(new GridParams(
			pager: Pager::buildFromPageNavigation($navigation),
			filter: new Params\StorageField\StorageFieldFilter($filter),
			sort: new Params\StorageField\StorageFieldSort($sort),
			select: new Params\StorageField\StorageFieldSelect($select),
		));
	}

	public function getAction(int $id): ?Entity\StorageField\StorageField
	{
		if (!$this->checkFieldAccess($id))
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
	): Entity\StorageField\StorageFieldCollection|array|null
	{
		if (!$this->checkStorageTypeAccess($storageId))
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

	/**
	 * Filtering and return field maps of a single storage for the storage activity settings dialog.
	 *
	 * @return array{filterFields: array, returnFields: array}
	 */
	public function getActivityFieldsMapAction(int $storageId): array
	{
		if (!$this->checkStorageTypeAccess($storageId))
		{
			return ['filterFields' => [], 'returnFields' => []];
		}

		return StorageActivityService::getActivityFieldsMaps($storageId);
	}

	public function addAction(StorageFieldDto $storageFieldDto): Entity\StorageField\StorageField|array|null
	{
		if (
			!$this->checkStorageTypeAccess($storageFieldDto->storageId)
			|| !$this->checkFieldType($storageFieldDto->type)
		)
		{
			return null;
		}

		$storageFieldEntity = Entity\StorageField\StorageField::mapFromArray($storageFieldDto->toArray());

		$addStorageFieldCommand = new Command\StorageField\AddStorageFieldCommand(
			storageField: $storageFieldEntity,
		);

		$result = $addStorageFieldCommand->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		if ($storageFieldDto->format)
		{
			return $result->getStorageField()?->toProperty();
		}

		/** @var Command\StorageField\StorageFieldResult $result */
		return $result->getStorageField();
	}

	public function updateAction(StorageFieldDto $storageFieldDto): Entity\StorageField\StorageField|array|null
	{
		if (
			!$this->checkFieldAccess((int)$storageFieldDto->id)
			|| !$this->checkStorageTypeAccess($storageFieldDto->storageId)
			|| !$this->checkFieldType(
				$storageFieldDto->type,
				(new StorageFieldProvider())->getById((int)$storageFieldDto->id)?->getType(),
			)
		)
		{
			return null;
		}

		$storageFieldEntity = Entity\StorageField\StorageField::mapFromArray($storageFieldDto->toArray());

		$updateStorageFieldCommand = new Command\StorageField\UpdateStorageFieldCommand(
			storageField: $storageFieldEntity,
		);

		$result = $updateStorageFieldCommand->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		if ($storageFieldDto->format)
		{
			return $result->getStorageField()?->toProperty();
		}

		/** @var Command\StorageField\StorageFieldResult $result */
		return $result->getStorageField();
	}

	public function deleteAction(int $id): ?array
	{
		if (!$this->checkFieldAccess($id))
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
}
