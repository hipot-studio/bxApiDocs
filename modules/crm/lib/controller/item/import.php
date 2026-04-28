<?php

namespace Bitrix\Crm\Controller\Item;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Import\Builder\DownloadFileUrlBuilder;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Contract\ImportSettings\HasDuplicateControlInterface;
use Bitrix\Crm\Import\Contract\ImportSettings\HasRequisiteImportInterface;
use Bitrix\Crm\Import\Controller\Response\ConfigureImportSettingsResponse;
use Bitrix\Crm\Import\Controller\Response\ImportResponse;
use Bitrix\Crm\Import\Controller\Response\RequisiteDuplicateControlTarget;
use Bitrix\Crm\Import\Dto\ImportOperationOptions;
use Bitrix\Crm\Import\Dto\UI\Table;
use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlBehavior;
use Bitrix\Crm\Import\Factory\ImportEntityFactory;
use Bitrix\Crm\Import\Factory\ErrorFactory;
use Bitrix\Crm\Import\Factory\FileFactory;
use Bitrix\Crm\Import\ImportOperation;
use Bitrix\Crm\Requisite\ImportHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\DisablePrefilters;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\JsonPayload;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Engine\Response\Json as JsonResponse;
use Bitrix\Main\Engine\Response\File;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Request;
use Bitrix\Main\Web\Json;
use JsonSerializable;

final class Import extends Base
{
	private readonly UserPermissions $permissions;
	private readonly ImportEntityFactory $entityFactory;
	private readonly DownloadFileUrlBuilder $downloadUrlBuilder;
	private readonly ErrorFactory $errors;
	private readonly FileFactory $fileFactory;

	private const IMPORT_LIMIT = 20;
	private const PREVIEW_TABLE_ROW_LIMIT = 5;

	/**
	 * @throws ServiceNotFoundException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$serviceLocator = ServiceLocator::getInstance();
		$this->entityFactory = $serviceLocator->get(ImportEntityFactory::class);
		$this->fileFactory = $serviceLocator->get(FileFactory::class);
		$this->downloadUrlBuilder = $serviceLocator->get(DownloadFileUrlBuilder::class);
		$this->errors = $serviceLocator->get(ErrorFactory::class);

		$this->permissions = Container::getInstance()->getUserPermissions();
	}

	#[DisablePrefilters([ Csrf::class ])]
	public function downloadExampleAction(int $entityTypeId, string $importSettingsJson): ?File
	{
		if (!$this->permissions->entityType()->canImportItems($entityTypeId))
		{
			return null;
		}

		if (!Json::validate($importSettingsJson))
		{
			return null;
		}

		$importSettings = Json::decode($importSettingsJson);
		if (!is_array($importSettings))
		{
			return null;
		}

		$entity = $this->entityFactory->createEntity($entityTypeId, $importSettings);
		if (!$entity instanceof ImportEntityInterface\HasExampleFileInterface)
		{
			return null;
		}

		return new File($entity->getExampleFilePath());
	}

	#[DisablePrefilters([ Csrf::class ])]
	public function downloadImportResultFileAction(int $entityTypeId, string $importFileId, string $rawType): ?File
	{
		if (!$this->permissions->entityType()->canImportItems($entityTypeId))
		{
			return null;
		}

		$filepath = $this->fileFactory->getTemporaryFile($importFileId, $rawType);
		if ($filepath === null)
		{
			return null;
		}

		return new File($filepath);
	}

	public function configureImportSettingsAction(JsonPayload $payload): ?JsonResponse
	{
		$entity = $this->getEntity($payload);
		if ($entity === null)
		{
			return null;
		}

		if (!$this->permissions->entityType()->canImportItems($entity->getSettings()->getEntityTypeId()))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$uploadResult = $this->fileFactory->uploadImportFile($entity->getSettings());
		if (!$uploadResult->isSuccess())
		{
			$this->addErrors($uploadResult->getErrors());

			return null;
		}

		$reader = $this->fileFactory->getImportFileReader($entity->getSettings());
		if ($reader === null)
		{
			$this->addError($this->errors->getImportFileNotSupportedError());

			return null;
		}

		if ($entity instanceof ImportEntityInterface\DependOnHeadersInterface)
		{
			$entity->setHeaders($reader->getHeaders());
		}

		/** @var array<string> $requiredFieldCaptions */
		$requiredFieldIds = $entity
			->getFields()
			->filter(static fn (ImportEntityFieldInterface $field) => $field->isRequired())
			->getIds();

		$requisiteDuplicateControlTargets = [];
		$settings = $entity->getSettings();

		if ($settings instanceof HasRequisiteImportInterface && $settings->getRequisiteOptions()->isImportRequisite())
		{
			$requisiteOptions = $settings->getRequisiteOptions();

			$options = [];
			if (!$requisiteOptions->isRequisitePresetAssociate())
			{
				$options['PRESET_IDS'] = [
					$requisiteOptions->getDefaultRequisitePresetId(),
				];
			}

			$importRequisiteInfo = ImportHelper::prepareEntityImportRequisiteInfo($settings->getEntityTypeId(), $options);
			$requisiteDupControlOptions = ImportHelper::getRequisiteDupControlImportOptions(
				headers: $entity->getFields()->toArray(),
				activeCountryList: $importRequisiteInfo['ACTIVE_COUNTRIES'],
			);

			foreach ($requisiteDupControlOptions as $option)
			{
				$requisiteDuplicateControlTargets[$option['countryId']] ??= new RequisiteDuplicateControlTarget(
					countryId: $option['countryId'],
					countryCaption: $option['countryName'],
				);

				$requisiteDuplicateControlTargets[$option['countryId']]->add(
					fieldId: "{$option['group']}__{$option['field']}",
					fieldCaption: $option['name'],
				);
			}
		}

		$importPreviewTable = Table::byReader($reader, self::PREVIEW_TABLE_ROW_LIMIT);

		$response = new ConfigureImportSettingsResponse(
			fileHeaders: $reader->getHeaders(),
			entityFields: $entity->getFields()->getAll(),
			fieldBindings: $entity->getFieldBindingMapper()->map($reader),
			filesize: $reader->getFileSize(),
			previewTable: $importPreviewTable,
			requiredFieldIds: $requiredFieldIds,
			requisiteDuplicateControlTargets: $requisiteDuplicateControlTargets,
		);

		return $this->success($response);
	}

	public function importAction(JsonPayload $payload): ?JsonResponse
	{
		$entity = $this->getEntity($payload);
		if ($entity === null)
		{
			return null;
		}

		if ($entity->getSettings()->getImportFileId() === null)
		{
			$this->addError($this->errors->getImportFileNotFoundError());

			return null;
		}

		$reader = $this->fileFactory->getImportFileReader($entity->getSettings());
		if ($reader === null)
		{
			$this->addError($this->errors->getImportFileNotSupportedError());

			return null;
		}

		if ($entity instanceof ImportEntityInterface\DependOnHeadersInterface)
		{
			$entity->setHeaders($reader->getHeaders());
		}

		$currentLine = $payload->getData()['currentLine'] ?? null;
		if (!is_numeric($currentLine) && (int)$currentLine < 0)
		{
			$this->addError($this->errors->getCurrentLineNotFoundError());

			return null;
		}

		$options = new ImportOperationOptions(
			reader: $reader,
			entity: $entity,
			fieldBindings: $entity->getSettings()->getFieldBindings(),
			startFrom: (int)$currentLine,
			limit: self::IMPORT_LIMIT,
		);

		$importResult = (new ImportOperation($options))->launch();

		$errorsPreviewTable = null;
		$downloadFailImportFileUrl = null;
		$downloadDuplicateImportFileUrl = null;

		if (!$importResult->isSuccess())
		{
			$errorsPreviewTable = Table::byReader($reader);

			$failImportWriter = $this->fileFactory->getFailImportWriter($entity->getSettings());
			$failImportWriter->writeHeaders($reader->getHeaders());

			foreach ($importResult->getErrorPackList() as $pack)
			{
				$errorMessages = $pack->getErrorMessages();
				foreach ($pack->rowIndexes as $rowIndex)
				{
					$row = $reader->readRow($rowIndex);
					if ($row === null)
					{
						continue;
					}

					$errorsPreviewTable->addRow(Table\Row::fromReaderRow($row, $errorMessages));

					// show errors into the first row
					$errorMessages = [];

					$failImportWriter->write($row);
				}
			}

			$downloadFailImportFileUrl = $this->downloadUrlBuilder
				->getDownloadFailImportUrl(
					entityTypeId: $entity->getSettings()->getEntityTypeId(),
					importFileId: $entity->getSettings()->getImportFileId(),
				)
			;
		}

		$importSettings = $entity->getSettings();
		if (
			$importSettings instanceof HasDuplicateControlInterface
			&& $importResult->hasDuplicates()
			&& $importSettings->getDuplicateControl()->getBehavior() === DuplicateControlBehavior::Skip
		)
		{
			$duplicateWriter = $this->fileFactory->getDuplicateImportWriter($importSettings);
			$duplicateWriter->writeHeaders($reader->getHeaders());

			foreach ($importResult->getDuplicateRowIndexes() as $duplicateRowIndex)
			{
				$row = $reader->readRow($duplicateRowIndex);
				if ($row !== null)
				{
					$duplicateWriter->write($row);
				}
			}

			$downloadDuplicateImportFileUrl = $this->downloadUrlBuilder
				->getDownloadDuplicateUrl(
					entityTypeId: $importSettings->getEntityTypeId(),
					importFileId: $importSettings->getImportFileId(),
				)
			;
		}

		$response = new ImportResponse(
			successImportCount: $importResult->getSuccessImportCount(),
			failImportCount: $importResult->getFailImportCount(),
			duplicateImportCount: $importResult->getDuplicateImportCount(),
			currentLine: $importResult->getCurrentLine(),
			progressedBytes: $importResult->getProgressedBytes(),
			isFinished: $importResult->isFinished(),
			errorsPreviewTable: $errorsPreviewTable,
			downloadFailImportFileUrl: $downloadFailImportFileUrl?->getUri(),
			downloadDuplicateImportFileUrl: $downloadDuplicateImportFileUrl?->getUri(),
		);

		return $this->success($response);
	}

	private function getEntity(JsonPayload $payload): ?ImportEntityInterface
	{
		$importSettingsRaw = $payload->getData()['importSettings'] ?? [];

		$entityTypeId = $importSettingsRaw['entityTypeId'] ?? null;
		if ($entityTypeId === null)
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError());

			return null;
		}

		if (!$this->permissions->entityType()->canImportItems($entityTypeId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$entity = $this->entityFactory->createEntity($entityTypeId, $importSettingsRaw);
		if ($entity === null)
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError());

			return null;
		}

		return $entity;
	}

	private function success(JsonSerializable $response): JsonResponse
	{
		$data = [
			'status' => AjaxJson::STATUS_SUCCESS,
			'data' => $response->jsonSerialize(),
			'errors' => [],
		];

		return new JsonResponse($data, Json::DEFAULT_OPTIONS);
	}
}
