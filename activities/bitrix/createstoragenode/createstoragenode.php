<?php

declare(strict_types=1);

use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Main\Localization\Loc;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Public\Command\StorageField\AddStorageFieldCommand;
use Bitrix\Bizproc\Public\Command;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldService;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageItemMapper;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * @property-write string Title
 * @property-write string StorageTitle
 * @property-write string StorageDescription
 * @property-write string StorageCode
 * @property-write array SelectedFields
 * @property-write int StorageId
 * @property-write ?array StorageFields
 * @property-write string Mode
 * @property-write ?string CreateErrorText
 */
class CBPCreateStorageNode extends BaseActivity implements IBPConfigurableActivity
{
	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'StorageTitle' => '',
			'StorageDescription' => '',
			'StorageCode' => '',
			'SelectedFields' => [],
			'Mode' => 'Y',

			//return
			'StorageId' => 0,
			'StorageFields' => null,
			'CreateErrorText' => null,
		];

		$this->setPropertiesTypes([
			'Title' => ['Type' => FieldType::STRING],
			'StorageId' => ['Type' => FieldType::INT],
			'CreateErrorText' => ['Type' => FieldType::STRING],
		]);
	}

	protected function internalExecute(): \Bitrix\Main\ErrorCollection
	{
		$errors = parent::internalExecute();

		if (empty($this->StorageTitle))
		{
			$error = ErrorMessage::EMPTY_PROP->getError([
				'#PROPERTY#' => Loc::getMessage('BPCSN_DESCRIPTION_TITLE_FIELD_NAME') ?? '',
			]);
			$errors->setError($error);

			return $errors;
		}

		$currentUser =  new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
		$userId = $currentUser->getId();
		$storageId = $this->findStorageId();
		$storageType = $this->createStorageTypeEntity($storageId);
		$command = $this->Mode && $storageId > 0
			? new Command\StorageType\UpdateStorageTypeCommand($userId, $storageType)
			: new Command\StorageType\AddStorageTypeCommand($userId, $storageType)
		;
		$result = $command->run();
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$errors->setError($error);
			}

			$this->CreateErrorText = $this->composeCreateErrorText($errors);

			return $errors;
		}

		/** @var Command\StorageType\StorageTypeResult $result */
		$storageType = $result->getStorageType();
		$fieldCodes = [];
		if ($storageType)
		{
			$storageId = $storageType->getId();
			$this->setProperty('StorageId', (int)$storageId);
			$this->addSystemFieldsToProperties();

			$fieldCollection = (new StorageFieldProvider())->getByStorageId((int)$storageId);
			foreach ($fieldCollection as $field)
			{
				$fieldCode = $field->getCode();
				$this->setProperty($fieldCode, $fieldCode);
				$fieldCodes[] = $fieldCode;
			}
		}

		if ((int)$storageId > 0 && !empty($this->SelectedFields))
		{
			$this->createStorageFields((int)$storageId, $fieldCodes, $errors);
		}

		return $errors;
	}

	private function createStorageTypeEntity(int $storageId): StorageType
	{
		$entity = (new StorageType())
			->setTitle(\CBPHelper::stringify($this->StorageTitle))
			->setDescription(\CBPHelper::stringify($this->StorageDescription))
			->setCode(\CBPHelper::stringify($this->StorageCode))
		;

		if ($storageId > 0)
		{
			$entity->setId($storageId);
		}

		return $entity;
	}

	private function addSystemFieldsToProperties(): void
	{
		if ((int)$this->StorageId <= 0)
		{
			return;
		}

		$fieldsMap = StorageItemMapper::getFieldsMap();
		$systemFields = self::getSystemFields();

		foreach ($systemFields as $systemField)
		{
			if (isset($fieldsMap[$systemField['ID']]))
			{
				$this->setProperty($systemField['ID'], $fieldsMap[$systemField['ID']]);
			}
		}
	}

	private function findStorageId(): int
	{
		$rawStorageCode = $this->StorageCode;
		$storageCode = CBPHelper::hasStringRepresentation($rawStorageCode) ? (string)$rawStorageCode : '';

		$provider = new StorageTypeProvider();
		$type = $provider->getType(['CODE' => $storageCode], ['ID']);

		return (int)$type?->getId();
	}

	private function createStorageFields(int $storageId, array $fieldCodes, \Bitrix\Main\ErrorCollection $errors): void
	{
		foreach ($this->SelectedFields as $storageField)
		{
			if (array_key_exists('code', $storageField) && in_array($storageField['code'], $fieldCodes, true))
			{
				continue;
			}

			try
			{
				$storageFieldEntity = StorageField::mapFromArray($storageField);
			}
			catch (Exception $exception)
			{
				$errors->setError(ErrorBuilder::buildFromException($exception));

				continue;
			}

			$storageFieldEntity->setStorageId($storageId);
			$addStorageFieldCommand = new AddStorageFieldCommand(
				storageField: $storageFieldEntity,
			);

			$result = $addStorageFieldCommand->run();
			if (!$result->isSuccess())
			{
				$errors->setError($result->getErrors()[0]);
			}
			else
			{
				$fieldCode = $storageFieldEntity->getCode();
				$this->setProperty($fieldCode, $fieldCode);
			}
		}

		if (is_array($this->StorageFields))
		{
			$this->setPropertiesTypes($this->StorageFields);
		}
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'StorageTitle' => [
				'Name' => Loc::getMessage('BPCSN_DESCRIPTION_TITLE_FIELD_NAME'),
				'FieldName' => 'StorageTitle',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Required' => true,
				'AllowSelection' => true
			],
			'StorageDescription' => [
				'Name' => Loc::getMessage('BPCSN_DESCRIPTION_DESCRIPTION_FIELD_NAME'),
				'FieldName' => 'StorageDescription',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Required' => false,
				'AllowSelection' => true
			],
			'StorageCode' => [
				'Name' => Loc::getMessage('BPCSN_DESCRIPTION_CODE_FIELD_NAME'),
				'FieldName' => 'StorageCode',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Required' => false,
				'AllowSelection' => true
			],
			'Mode' => [
				'Name' => Loc::getMessage('BPCSN_DESCRIPTION_MODE_FIELD_NAME') ?? '',
				'FieldName' => 'Mode',
				'Type' => \Bitrix\Bizproc\FieldType::BOOL,
				'Required' => false,
				'Default' => 'Y',
				'AllowSelection' => false
			],
			'SelectedFields' => [
				'Name' => Loc::getMessage('BPCSN_DESCRIPTION_SELECTED_FIELDS_NAME'),
				'FieldName' => 'SelectedFields',
				'Type' => \Bitrix\Bizproc\FieldType::CUSTOM,
				'Required' => false,
				'AllowSelection' => true,
				'CustomType' => 'storage-fields',
				'Options' => [
					'codeCaption' => Loc::getMessage('BPCSN_DESCRIPTION_FIELD_CODE_CAPTION') ?? '',
					'copyNotification' => Loc::getMessage('BPCSN_DESCRIPTION_FIELD_COPY_NOTIFICATION') ?? '',
				]
			],
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$result = parent::extractPropertiesValues($dialog, $fieldsMap);
		$data = $result->getData();

		$currentValues = $dialog->getCurrentValues();
		$systemFields = self::getSystemFields();
		$storageFields = [];
		foreach ($systemFields as $systemField)
		{
			$storageFields[$systemField['ID']] = [
				'Name' => $systemField['NAME'],
				'Type' => $systemField['TYPE'],
			];
		}
		$data['StorageFields'] = $storageFields;
		if (!empty($currentValues['SelectedFields']))
		{
			$selectedFields = [];
			foreach ($currentValues['SelectedFields'] as $field)
			{
				$jsonField = \CBPHelper::stringify($field);
				if (empty($jsonField) || !Json::validate($jsonField))
				{
					$result->addError(ErrorMessage::GET_DATA_ERROR->getError());

					return $result;
				}

				$storageField = Json::decode($jsonField);
				if (!static::validateStorageField($storageField))
				{
					$result->addError(ErrorMessage::GET_DATA_ERROR->getError());

					return $result;
				}

				$selectedFields[] = $storageField;
				$data['StorageFields'][$storageField['code']] = [
					'Name' => $storageField['name'],
					'Type' => $storageField['type']
				];
			}

			$data['SelectedFields'] = $selectedFields;
		}

		$result->setData($data);

		return $result;
	}

	private static function validateStorageField(array $storageField): bool
	{
		$requiredFields = ['storageId', 'code', 'sort', 'name', 'type'];

		foreach ($requiredFields as $field)
		{
			if (!array_key_exists($field, $storageField) || CBPHelper::isEmptyValue($storageField[$field]))
			{
				return false;
			}
		}

		if (!array_key_exists('multiple', $storageField) || !array_key_exists('mandatory', $storageField))
		{
			return false;
		}

		return true;
	}

	private static function getSystemFields(): array
	{
		$fieldService = new FieldService();
		$fields = $fieldService->getEntityFields();

		$supportedFields = [
			'ID',
			'CODE',
			'WORKFLOW_ID',
			'DOCUMENT_ID',
			'TEMPLATE_ID',
			'CREATED_BY',
			'CREATED_TIME',
		];

		$result = [];
		foreach ($fields as $field)
		{
			if (in_array($field['ID'], $supportedFields, true))
			{
				$result[$field['ID']] = $field;
			}
		}

		return $result;
	}

	private function composeCreateErrorText(\Bitrix\Main\ErrorCollection $errors): string
	{
		$messages = [];
		foreach ($errors as $error)
		{
			if ($error instanceof \Bitrix\Main\Error && $error->getMessage())
			{
				$messages[] = $error->getMessage();
			}
		}

		return implode(', ', $messages);
	}
}
