<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Activity\Interface\ActivityContentBlockProviderInterface;
use Bitrix\Bizproc\Public\Activity\Interface\ContentBlockScopeProducerInterface;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\ManagedAgentResourceRegistry;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Internal\Exception\Exception;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Public\Command\StorageField\AddStorageFieldCommand;
use Bitrix\Bizproc\Public\Command;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;
use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Service\StorageActivity\StorageActivityService;
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
class CBPCreateStorageNode extends BaseActivity implements IBPConfigurableActivity, ActivityContentBlockProviderInterface, ContentBlockScopeProducerInterface
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

	public static function getContentBlock(array $properties, ?\Bitrix\Bizproc\Activity\Dto\ContentBlockContext $context = null): ?\Bitrix\Bizproc\Activity\Dto\ContentBlock
	{
		return StorageActivityService::makeStorageContentBlock(
			(string)($properties['StorageTitle'] ?? ''),
			(string)Loc::getMessage('BPCSN_DESCRIPTION_TITLE_FIELD_NAME'),
		);
	}

	public static function getScopeContribution(): array
	{
		// Publish the dynamic storage title (code => title) so read/write/delete nodes referencing it
		// by code can render its name at design time, before the storage exists in the catalog.
		return [
			'namespace' => StorageActivityService::CONTENT_BLOCK_SCOPE_NAMESPACE,
			'keyProperty' => 'StorageCode',
			'labelProperty' => 'StorageTitle',
		];
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

		$userId = $this->resolveActingUserId();
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

	/**
	 * Author of the storage type: the user who started the process for a template of the managed system AI
	 * agent registry, the global current user for every other template.
	 *
	 * A managed agent may run in a background context (the cleanup agent of the lifecycle, a scenario
	 * autostart) where there is no current user at all, so its storage would be created by user 0.
	 */
	private function resolveActingUserId(): int
	{
		return $this->resolveManagedStarterId()
			?? (new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser))->getId()
		;
	}

	/**
	 * Starter of the process when its template is managed, null otherwise, including a registry that is not
	 * available at all, so an unmanaged template keeps the previous behaviour unchanged.
	 */
	private function resolveManagedStarterId(): ?int
	{
		$workflow = $this->workflow;
		if (!$workflow instanceof CBPWorkflow)
		{
			return null;
		}

		$templateId = $workflow->getTemplateId();
		$locator = ServiceLocator::getInstance();
		if ($templateId <= 0 || !$locator->has(ManagedAgentResourceRegistry::SERVICE_CODE))
		{
			return null;
		}

		try
		{
			/** @var ManagedAgentResourceRegistry $registry */
			$registry = $locator->get(ManagedAgentResourceRegistry::SERVICE_CODE);

			return $registry->isManagedTemplate($templateId) ? $workflow->getStartedBy() : null;
		}
		catch (\Throwable)
		{
			return null;
		}
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

	/**
	 * Resolved by the shared resolver of the storage nodes, so this node and every consumer of the same code reach
	 * one and the same storage: the resolve is exact - a code that merely contains this one must not make the node
	 * rewrite a foreign storage - and an empty or unknown code gives 0 and sends the node to the add branch.
	 *
	 * The resolver memoises hits for the whole process, and sharing that memo with the consumers is safe because a
	 * miss is never cached: a code this node is about to create resolves to 0 now and to the new id afterwards. A
	 * cached hit cannot go stale either - a storage type is dropped by the catalog controllers alone, in a request
	 * of its own; no node of a running workflow deletes one (the delete node removes items, not the type).
	 */
	private function findStorageId(): int
	{
		$rawStorageCode = $this->StorageCode;
		$storageCode = CBPHelper::hasStringRepresentation($rawStorageCode) ? (string)$rawStorageCode : '';

		return StorageActivityService::resolveStorageId(null, $storageCode);
	}

	private function createStorageFields(int $storageId, array $fieldCodes, \Bitrix\Main\ErrorCollection $errors): void
	{
		$position = 0;
		foreach ($this->SelectedFields as $storageField)
		{
			++$position;

			if (!is_array($storageField))
			{
				$errors->setError(ErrorMessage::GET_DATA_ERROR->getError());

				continue;
			}

			if (array_key_exists('code', $storageField) && in_array($storageField['code'], $fieldCodes, true))
			{
				continue;
			}

			$storageField = self::withResolvedSort($storageField, $position);

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
				'Required' => true,
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
				'ShowFieldLabel' => false,
				'FieldName' => 'SelectedFields',
				'Type' => \Bitrix\Bizproc\FieldType::CUSTOM,
				'Required' => true,
				'AllowSelection' => true,
				'CustomType' => 'storageFieldSelector',
				'Options' => [
					'codeCaption' => Loc::getMessage('BPCSN_DESCRIPTION_FIELD_CODE_CAPTION') ?? '',
					'copyNotification' => Loc::getMessage('BPCSN_DESCRIPTION_FIELD_COPY_NOTIFICATION') ?? '',
					'addFieldButton' => Loc::getMessage('BPCSN_DESCRIPTION_ADD_FIELD_BUTTON') ?? '',
				]
			],
		];
	}

	public static function getAiPassthroughSettingNames(array $documentType): array
	{
		// Persisted but not typable via getPropertiesMap(): CUSTOM field selector and the computed field map.
		return ['SelectedFields', 'StorageFields'];
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
		if (empty($currentValues['SelectedFields']))
		{
			$result->addError(
				new \Bitrix\Main\Error(Loc::getMessage('BPCSN_DESCRIPTION_EMPTY_FIELDS_ERROR') ?? '')
			);

			return $result;
		}

		$selectedFields = [];
		$position = 0;
		foreach ($currentValues['SelectedFields'] as $field)
		{
			++$position;

			$storageField = static::readStorageField($field);
			if ($storageField === null || !static::validateStorageField($storageField))
			{
				$result->addError(ErrorMessage::GET_DATA_ERROR->getError());

				return $result;
			}

			$selectedFields[] = self::withResolvedSort($storageField, $position);
			$data['StorageFields'][$storageField['code']] = [
				'Name' => $storageField['name'],
				'Type' => $storageField['type']
			];
		}

		$data['SelectedFields'] = $selectedFields;

		$result->setData($data);

		return $result;
	}

	/**
	 * One column of the selector, in either of the two shapes the dialog is submitted in: the JSON string the
	 * field selector of the editor writes into a hidden input, and the object of the published AI schema, which
	 * an agent composes and a template then carries. Null means the shape is neither.
	 */
	private static function readStorageField(mixed $field): ?array
	{
		if (is_array($field))
		{
			return $field;
		}

		$jsonField = \CBPHelper::stringify($field);
		if (empty($jsonField) || !Json::validate($jsonField))
		{
			return null;
		}

		$decoded = Json::decode($jsonField);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * The column with the place it takes among the others settled. A column composed against the published
	 * schema carries no `sort` - the schema declares it optional - so the order the list itself states is what
	 * the server writes, and a column that names its own place keeps it.
	 */
	private static function withResolvedSort(array $storageField, int $position): array
	{
		if (!CBPHelper::isEmptyValue($storageField['sort'] ?? null))
		{
			return $storageField;
		}

		$storageField['sort'] = StorageFieldTable::DEFAULT_SORT + $position * 10;

		return $storageField;
	}

	/**
	 * The keys a column has to carry whichever shape it arrived in. `storageId` is not among them: the node
	 * resolves the storage from its own code and overwrites the value inside every element
	 * ({@see self::createStorageFields()}). Neither is `sort`, which the server settles above.
	 */
	private static function validateStorageField(array $storageField): bool
	{
		$requiredFields = ['code', 'name', 'type'];

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
		return StorageActivityService::getSystemFields([
			'ID',
			'WORKFLOW_ID',
			'DOCUMENT_ID',
			'TEMPLATE_ID',
			'CREATED_BY',
			'CREATED_TIME',
		]);
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
