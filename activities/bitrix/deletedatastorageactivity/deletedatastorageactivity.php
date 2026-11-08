<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Public\Activity\Interface\ActivityContentBlockProviderInterface;
use Bitrix\Bizproc\Public\Activity\Interface\ContentBlockScopeConsumerInterface;
use Bitrix\Bizproc\Activity\Dto\ContentBlock;
use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Internal\Service\StorageActivity\StorageActivityService;

/**
 * @property-write int StorageId
 * @property-write array DynamicFilterFields
 * @property-write int ItemId
 * @property-write string DeleteMode
 * @property-write string StorageCode
 * @property-write string IsExpanded
 */
class CBPDeleteDataStorageActivity extends BaseActivity implements IBPConfigurableActivity, IBPEventActivity, IBPActivityExternalEventListener, ActivityContentBlockProviderInterface, ContentBlockScopeConsumerInterface
{
	use \Bitrix\Bizproc\Activity\Mixins\EntityFilter;

	private const DELETE_MODE_MULTIPLE = 'multiple';
	private const DELETE_MODE_ALL = 'all';

	/** The field the fail-closed filter contradicts itself on: every storage carries it, so it always resolves. */
	private const FAIL_CLOSED_FILTER_FIELD = 'ID';

	private ?array $filterCache = null;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'StorageId' => 0,
			'DynamicFilterFields' => ['items' => []],
			'ItemId' => 0,
			'DeleteMode' => static::DELETE_MODE_MULTIPLE,
			'StorageCode' => '',
			'IsExpanded' => 'Y',
		];

		$this->setPropertiesTypes([
			'StorageId' => ['Type' => FieldType::INT],
			'DeleteMode' => ['Type' => FieldType::STRING],
		]);
	}

	protected function prepareProperties(): void
	{
		parent::prepareProperties();

		if ($this->DeleteMode === self::DELETE_MODE_MULTIPLE)
		{
			try
			{
				$this->preparedProperties['ItemId'] = $this->findStorageItemId();
			}
			catch (\Bitrix\Main\ArgumentException $exception)
			{
				$this->preparedProperties['ItemId'] = 0;
			}
		}
	}

	protected function findStorageItemId(): int
	{
		$storageId = $this->preparedProperties['StorageId'];
		if (!$storageId)
		{
			return 0;
		}

		$provider = new StorageItemProvider($storageId);
		$filter = $this->getPreparedFilter($storageId);
		if (!$this->isOrmFilterValid() || StorageActivityService::isOrmFilterEmpty($filter))
		{
			return 0;
		}

		$item = $provider->getItems([
			'filter' => $filter,
			'select' => ['ID'],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])?->getFirstCollectionItem();

		return $item ? $item->getId() : 0;
	}

	protected function deleteStorageItemIds(int $storageId): void
	{
		$filter = $this->getPreparedFilter($storageId);
 		\Bitrix\Bizproc\Infrastructure\Stepper\StorageItemDeleteStepper::bindStorage(
			 $storageId,
			 $filter,
			 $this->getWorkflowInstanceId(),
			 $this->name,
		);
	}

	protected function deleteAllStorageItemIds(int $storageId): void
	{
		\Bitrix\Bizproc\Infrastructure\Stepper\StorageItemDeleteStepper::bindStorage(
			$storageId,
			[],
			$this->getWorkflowInstanceId(),
			$this->name
		);
	}

	private function findStorageId(): int
	{
		$storageId = $this->StorageId;
		$rawStorageCode = $this->StorageCode;
		$storageCode = CBPHelper::hasStringRepresentation($rawStorageCode) ? (string)$rawStorageCode : '';

		return StorageActivityService::resolveStorageId($storageId, $storageCode);
	}

	protected function checkProperties(): \Bitrix\Main\ErrorCollection
	{
		$errors = parent::checkProperties();

		$storageId = (int)$this->StorageId;
		if (!$storageId && empty($this->StorageCode))
		{
			$errors->setError(new Error(Loc::getMessage('BIZPROC_SDA_STORAGE_NOT_SELECTED')));
		}

		if ($this->DeleteMode === self::DELETE_MODE_MULTIPLE && $this->ItemId <= 0)
		{
			$errors->setError(new Error(Loc::getMessage('BIZPROC_SDA_ITEM_NOT_FOUND')));
		}

		if (!in_array($this->DeleteMode, [self::DELETE_MODE_MULTIPLE, self::DELETE_MODE_ALL]))
		{
			$errors->setError(new Error(Loc::getMessage('BIZPROC_SDA_INVALID_DELETE_MODE')));
		}

		return $errors;
	}

	public function execute(): int
	{
		$storageId = $this->findStorageId();
		if ($storageId <= 0)
		{
			$this->trackError(Loc::getMessage('BIZPROC_SDA_STORAGE_NOT_FOUND') ?? '');

			return CBPActivityExecutionStatus::Closed;
		}

		$this->arProperties['StorageId'] = $storageId;
		$this->preparedProperties['StorageId'] = $storageId;
		$this->prepareProperties();

		$errors = $this->checkProperties();

		if (!$errors->isEmpty())
		{
			foreach ($errors as $error)
			{
				$this->trackError($error->getMessage());
			}

			return CBPActivityExecutionStatus::Closed;
		}

		$this->subscribe($this);

		try
		{
			match ($this->DeleteMode)
			{
				self::DELETE_MODE_MULTIPLE => $this->deleteStorageItemIds($storageId),
				self::DELETE_MODE_ALL => $this->deleteAllStorageItemIds($storageId),
			};

			return CBPActivityExecutionStatus::Executing;
		}
		catch (\Throwable $exception)
		{
			$this->unsubscribe($this);
			$this->trackError($exception->getMessage());

			return CBPActivityExecutionStatus::Closed;
		}
	}

	public function subscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$this->workflow->addEventHandler($this->name, $eventHandler);
	}

	public function unsubscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$this->workflow->removeEventHandler($this->name, $eventHandler);
	}

	public function onExternalEvent($arEventParameters = [])
	{
		$this->unsubscribe($this);
		$this->workflow->closeActivity($this);
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		$this->arProperties['ItemId'] = 0;
		$this->preparedProperties['ItemId'] = 0;
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		// DynamicFilterFields is a standard `conditiongroup` field now: the base extraction
		// routes it through BaseType\ConditionGroup::extractValue (same request prefix
		// `filter_fields_`, same Engine\ConditionGroup value format). Only scalar post-casts remain.
		$result = parent::extractPropertiesValues($dialog, $fieldsMap);

		if ($result->isSuccess())
		{
			$currentValues = $result->getData();
			$currentValues['StorageId'] = (int)$currentValues['StorageId'];
			$currentValues['DeleteMode'] = (string)($currentValues['DeleteMode'] ?? '');

			$result->setData($currentValues);
		}

		return $result;
	}

	public static function getContentBlock(array $properties, ?\Bitrix\Bizproc\Activity\Dto\ContentBlockContext $context = null): ?ContentBlock
	{
		return StorageActivityService::getContentBlock($properties, $context);
	}

	public static function getScopeConsumption(): array
	{
		return StorageActivityService::getScopeConsumption();
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$storages = StorageActivityService::getStorageTypes();
		$storageIds = array_map('intval', array_keys($storages));

		$filteringFields = StorageActivityService::getFilteringFieldsMapByStorageIds($storageIds);
		$filteringFieldsMap = [
			0 => array_values(StorageActivityService::getFilteringFieldsMap(0)),
		];

		foreach ($storages as $id => $title)
		{
			$filteringFieldsMap[$id] = array_values($filteringFields[$id] ?? []);
		}

		return [
			'StorageId' => [
				'Name' => Loc::getMessage('BIZPROC_SDA_STORAGE_ID_PROPERTY'),
				'FieldName' => 'storage_id',
				'Type' => FieldType::ENTITYSELECTOR,
				'Settings' => [
					'entity' => ['id' => 'bizproc-storage'],
					'dialogOptions' => [
						'width' => 445,
						'height' => 300,
					],
				],
				'Required' => false,
				'RequiredMark' => true,
				'AllowSelection' => false,
			],
			'StorageCode' => [
				'Name' => '',
				'FieldName' => 'storage_code',
				'Description' => Loc::getMessage('BIZPROC_SDA_STORAGE_CODE_DESCRIPTION'),
				'Type' => FieldType::STRING,
				'Required' => false,
				'Hidden' => true,
			],
			'DeleteMode' => [
				'Name' => Loc::getMessage('BIZPROC_SDA_DELETE_MODE'),
				'FieldName' => 'delete_mode',
				'Type' => FieldType::SELECT,
				'Options' => [
					static::DELETE_MODE_MULTIPLE => Loc::getMessage('BIZPROC_SDA_DELETE_MODE_MULTIPLE'),
					static::DELETE_MODE_ALL => Loc::getMessage('BIZPROC_SDA_DELETE_MODE_ALL'),
				],
				'Required' => true,
				'AllowSelection' => false,
			],
			'DynamicFilterFields' => [
				'Name' => Loc::getMessage('BIZPROC_SDA_FILTER_FIELDS_PROPERTY'),
				'ShowFieldLabel' => false,
				'FieldName' => 'filter_fields',
				'Type' => FieldType::CONDITIONGROUP,
				'Required' => false,
				'Settings' => [
					// Standard `conditiongroup` field: the control renders itself server-side
					// and mounts from data-config. The BaseType reads only Fields/documentType/Prefix
					// (DTO-01); the starting field set is the virtual storage (id 0).
					'Fields' => $filteringFieldsMap[0],
					'documentType' => \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType(),
					'Prefix' => 'filter_fields_',
					// Pilot-private (NOT part of DTO-01, ignored by the BaseType render): the full
					// per-storage field map is delivered to the activity JS via `activityFields`
					// (afterFormRender) and re-fed with setFields() when the storage changes.
					'filterFieldsMap' => $filteringFieldsMap,
					'caption' => [
						'head' => Loc::getMessage('BIZPROC_SDA_FILTER_FIELDS_PROPERTY'),
						'collapsed' => Loc::getMessage('BIZPROC_SDA_FILTER_FIELDS_COLLAPSED_TEXT'),
					],
				],
			],
			'IsExpanded' => [
				'Name' => '',
				'FieldName' => 'is_expanded',
				'Type' => FieldType::STRING,
				'Required' => false,
				'AllowSelection' => false,
				'Hidden' => true,
				'Default' => 'Y',
			],
		];
	}

	public static function getAiPassthroughSettingNames(array $documentType): array
	{
		// DynamicFilterFields persists as a CONDITIONGROUP filter tree, which getPropertiesMap() types as a
		// scalar, so only a passthrough keeps the delete filter through the reverse round-trip.
		return ['DynamicFilterFields'];
	}

	/**
	 * A saved filter carrying a condition the engine cannot read selects NOTHING here, rather than selecting by
	 * the conditions that are left. The multiple mode deletes every record the filter selects
	 * ({@see self::deleteStorageItemIds()}), so a filter short of a condition deletes records the saved value
	 * never asked for - and a deletion cannot be looked at afterwards and undone.
	 *
	 * This is where the two storage nodes deliberately differ: the read node keeps the readable conditions and
	 * reads by them ({@see \CBPReadDataStorageActivity::filterFieldsOrNull()}), because reading too much is
	 * answered by the block's own output, while deleting too much is answered by nothing.
	 *
	 * A value the group cannot read at all is unchanged by this: it yields an empty filter, and the mode then
	 * refuses the block with "record not found" ({@see self::checkProperties()}).
	 */
	private function prepareFilterContext(int $storageId): array
	{
		if ($this->hasUnreadableCondition())
		{
			return $this->getImpossibleOrmFilter(self::FAIL_CLOSED_FILTER_FIELD);
		}

		$conditionGroup = new ConditionGroup($this->filterFieldsOrNull());
		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		$fieldsMap = StorageActivityService::getFilteringFieldsMap($storageId);

		$filter = $this->getOrmFilter($conditionGroup, $documentType, $fieldsMap);

		return $this->isOrmFilterComplete() ? $filter : $this->getImpossibleOrmFilter(self::FAIL_CLOSED_FILTER_FIELD);
	}

	/**
	 * The filter in the shape the condition group takes, or nothing. A template may carry it otherwise - an older
	 * save, or a graph an agent sent before the shape of the value was described - and a TypeError here would
	 * kill the whole process instead. A filter left with no condition deletes nothing: the multiple mode refuses
	 * the block with "record not found" ({@see self::checkProperties()}).
	 */
	private function filterFieldsOrNull(): ?array
	{
		return is_array($this->DynamicFilterFields) ? $this->DynamicFilterFields : null;
	}

	/** Reject conditions lost or coerced by ConditionGroup before EntityFilter can diagnose them. */
	private function hasUnreadableCondition(): bool
	{
		$items = is_array($this->DynamicFilterFields) ? ($this->DynamicFilterFields['items'] ?? null) : null;
		if (!is_array($items))
		{
			return false;
		}

		foreach ($items as $item)
		{
			$condition = is_array($item) ? ($item[0] ?? null) : null;
			if (
				!is_array($condition)
				|| empty($condition['field'])
				|| !is_string($condition['operator'] ?? null)
			)
			{
				return true;
			}

			foreach (['field', 'object'] as $key)
			{
				if (isset($condition[$key]) && !is_scalar($condition[$key]))
				{
					return true;
				}
			}
		}

		return false;
	}

	private function getPreparedFilter(int $storageId): array
	{
		if (!isset($this->filterCache[$storageId]))
		{
			$this->filterCache[$storageId] = $this->prepareFilterContext($storageId);
		}

		return $this->filterCache[$storageId];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	public static function validateProperties($testProperties = [], \CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (
			(int)($testProperties['StorageId'] ?? 0) <= 0
			&& CBPHelper::isEmptyValue($testProperties['StorageCode'] ?? null)
		)
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => 'FieldValue',
				'message' => Loc::getMessage('BIZPROC_SDA_EMPTY_STORAGE_ID_OR_CODE_MSGVER_1'),
			];
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
	}
}
