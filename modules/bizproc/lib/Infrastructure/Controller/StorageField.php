<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Public\Command\StorageField\StorageFieldDto;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Bizproc\Public\Service\StorageField\FieldService;
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
		if (!$this->checkAdminAccess())
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

	private function checkAdminAccess(): bool
	{
		return (new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
	}

	public function getPreparedFormAction(StorageFieldDto $storageFieldDto): ?Entity\StorageField\StorageField
	{
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

	public function addAction(StorageFieldDto $storageFieldDto): Entity\StorageField\StorageField|array|null
	{
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
