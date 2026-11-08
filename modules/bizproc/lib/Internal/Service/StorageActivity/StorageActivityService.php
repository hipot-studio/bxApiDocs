<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageActivity;

use Bitrix\Bizproc\Activity\Dto\ContentBlock;
use Bitrix\Bizproc\Activity\Dto\ContentBlockContext;
use Bitrix\Bizproc\Activity\Dto\ContentBlockItem;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageFieldCollection;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageItemMapper;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldService;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageEavMigrationPhase;
use Bitrix\Main\Localization\Loc;

final class StorageActivityService
{
	/** Scope namespace under which CreateStorageNode declares dynamic storage titles (code => title). */
	public const CONTENT_BLOCK_SCOPE_NAMESPACE = 'storage';

	private const DEFAULT_SUPPORTED_FIELDS = [
		'ID',
		'WORKFLOW_ID',
		'DOCUMENT_ID',
		'TEMPLATE_ID',
		'CREATED_BY',
		'CREATED_TIME',
	];

	/**
	 * Request-local caches for content-block resolution. getContentBlock() is called once per
	 * storage node while a diagram is opened/saved/cataloged, so without caching it produces N+1
	 * ORM lookups on large schemas. Keyed by storageId / storageCode.
	 *
	 * @var array<int, string>
	 */
	private static array $storageTitleByIdCache = [];

	/** @var array<string, int> */
	private static array $storageIdByCodeCache = [];

	/**
	 * Memoized label of the storage name, keyed by the language it was resolved in. A miss is left
	 * out, so a language switch or a late translation delivery is not frozen into an empty label.
	 *
	 * @var array<string, string>
	 */
	private static array $storageContentBlockLabelByLanguage = [];

	private static function buildFieldEntry(string $id, string $name, string $type): array
	{
		return [
			'Id' => $id,
			'Name' => $name,
			'Type' => $type,
			'Expression' => "{{{$name}}}",
			'SystemExpression' => "{=Storage:{$id}}",
			'Options' => null,
			'Settings' => null,
			'Multiple' => false,
		];
	}

	public static function getStorageTypes(): array
	{
		$options = [];

		$provider = new StorageTypeProvider();
		$storages = $provider->getAllForActivity();

		foreach ($storages as $storage)
		{
			$options[$storage->getId()] = $storage->getTitle();
		}

		return $options;
	}

	public static function getFilteringFieldsMap(
		int $storageId,
		?StorageFieldCollection $prefetchedFields = null,
	): array
	{
		$map = [];

		$fields = (new FieldService($storageId))->getEntityFields();
		foreach ($fields as $field)
		{
			if (!in_array($field['ID'], self::DEFAULT_SUPPORTED_FIELDS, true))
			{
				continue;
			}

			$type = $field['TYPE'] === 'integer' ? FieldType::INT : $field['TYPE'];
			$map[$field['ID']] = self::buildFieldEntry($field['ID'], $field['NAME'], $type);
		}

		if (self::isMigrationCompleted())
		{
			$fieldCollection = $prefetchedFields ?? (new StorageFieldProvider())->getByStorageId($storageId);
			foreach ($fieldCollection as $field)
			{
				$property = $field->toProperty();
				$map[$property['FieldName']] = self::buildFieldEntry(
					$property['FieldName'],
					$property['Name'],
					$property['Type'],
				);
			}
		}

		return $map;
	}

	/**
	 * Both activity dialog maps of a single storage, built from one field load.
	 *
	 * @return array{filterFields: array, returnFields: array}
	 */
	public static function getActivityFieldsMaps(int $storageId): array
	{
		$fields = self::loadStorageFields($storageId);

		return [
			'filterFields' => array_values(self::getFilteringFieldsMap($storageId, $fields)),
			'returnFields' => self::buildReturnFieldsMap($fields),
		];
	}

	public static function loadStorageFields(int $storageId): StorageFieldCollection
	{
		if ($storageId <= 0)
		{
			return new StorageFieldCollection();
		}

		try
		{
			return (new StorageFieldProvider())->getByStorageId($storageId);
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			return new StorageFieldCollection();
		}
	}

	public static function buildReturnFieldsMap(StorageFieldCollection $fields): array
	{
		$fieldsMap = [];

		foreach ($fields as $field)
		{
			$property = $field->toProperty();
			$fieldsMap[$property['FieldName']] = $property;
		}

		return self::getReturnableSystemFields() + $fieldsMap;
	}

	/**
	 * Types a column of a storage may be created with, as the field editor offers them: its allow-list
	 * intersected with the field types the runtime actually carries, keyed by type id and named the way the
	 * editor names them.
	 *
	 * One reading for the two authors of a column - the person filling the editor form and the AI schema of
	 * `createstoragenode` - so a type offered to one of them is a type the other may use as well.
	 *
	 * @return array<string, string> type id => localized name
	 */
	public static function getFieldTypes(): array
	{
		$allowedTypes = \Bitrix\Bizproc\Public\Service\StorageField\FieldService::EDITABLE_TYPES;

		$documentTypes = \CBPHelper::GetDocumentFieldTypes();

		$fieldTypes = [];
		foreach ($allowedTypes as $type)
		{
			if (isset($documentTypes[$type]))
			{
				$fieldTypes[$type] = (string)$documentTypes[$type]['Name'];
			}
		}

		return $fieldTypes;
	}

	public static function getSystemFields(?array $supportedFields = null): array
	{
		$fieldService = new FieldService();
		$fields = $fieldService->getEntityFields();

		$supportedFields ??= self::DEFAULT_SUPPORTED_FIELDS;

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

	public static function getReturnableSystemFields(): array
	{
		$fieldService = new FieldService();
		$fields = $fieldService->getEntityFields();

		$fieldsMap = StorageItemMapper::getFieldsMap();

		$systemFields = [];
		foreach ($fields as $field)
		{
			if (
				isset($fieldsMap[$field['ID']])
				&& in_array($field['ID'], self::DEFAULT_SUPPORTED_FIELDS, true)
			)
			{
				$systemFields[$fieldsMap[$field['ID']]] = [
					'Name' => $field['NAME'],
					'FieldName' => $fieldsMap[$field['ID']],
					'Type' => $field['TYPE'],
					'Required' => false,
					'AllowSelection' => true,
				];
			}
		}

		return $systemFields;
	}

	/**
	 * Exact CODE resolve, never a substring one: a node names the single storage it was configured for, so a
	 * LIKE '%code%' lookup would answer with any storage whose code merely contains it - and '%' or '_' written
	 * into the code itself would turn the lookup into a wildcard matching foreign storage, picked by insertion
	 * order because such a lookup takes the first row of an unsorted limit 1.
	 */
	public static function resolveStorageId(?int $storageId, ?string $storageCode): int
	{
		if ($storageId !== null && $storageId > 0)
		{
			return $storageId;
		}

		if ($storageCode === null || $storageCode === '')
		{
			return 0;
		}

		if (isset(self::$storageIdByCodeCache[$storageCode]))
		{
			return self::$storageIdByCodeCache[$storageCode];
		}

		$provider = new StorageTypeProvider();
		$type = $provider->getByCode($storageCode);

		$id = (int)$type?->getId();
		if ($id > 0)
		{
			self::$storageIdByCodeCache[$storageCode] = $id;
		}

		return $id;
	}

	/**
	 * Declarative scope consumption shared by all storage read/write/delete nodes. Lets the editor
	 * client resolve a dynamic storage title (declared by an on-canvas CreateStorageNode) reactively.
	 */
	public static function getScopeConsumption(): array
	{
		return [
			'namespace' => self::CONTENT_BLOCK_SCOPE_NAMESPACE,
			'keyProperty' => 'StorageCode',
			'emptyLabel' => (string)Loc::getMessage('BIZPROC_STORAGE_ACTIVITY_CONTENT_BLOCK_EMPTY'),
		];
	}

	public static function getContentBlock(
		array $properties,
		?ContentBlockContext $context = null,
	): ?ContentBlock
	{
		$valueLabel = self::resolveStorageContentBlockLabel();

		$storageId = isset($properties['StorageId']) ? (int)$properties['StorageId'] : 0;
		$storageCode = (string)($properties['StorageCode'] ?? '');
		if ($storageId <= 0 && $storageCode !== '')
		{
			$storageId = self::resolveStorageId(null, $storageCode);
		}

		if ($storageId > 0)
		{
			return self::makeStorageContentBlock(self::resolveStorageTitleById($storageId), $valueLabel);
		}

		if ($storageCode !== '')
		{
			$dynamicTitle = $context?->scope?->resolve(self::CONTENT_BLOCK_SCOPE_NAMESPACE, $storageCode);
			if ($dynamicTitle !== null)
			{
				return self::makeStorageContentBlock($dynamicTitle, $valueLabel);
			}
		}

		return self::makeStorageContentBlock('', $valueLabel);
	}

	private static function resolveStorageTitleById(int $storageId): string
	{
		if (isset(self::$storageTitleByIdCache[$storageId]))
		{
			return self::$storageTitleByIdCache[$storageId];
		}

		$title = (string)((new StorageTypeProvider())->getById($storageId)?->getTitle() ?? '');

		return self::$storageTitleByIdCache[$storageId] = $title;
	}

	/**
	 * @param string $valueLabel Label the calling activity puts on the storage name in its settings
	 *        summary. An empty label leaves the block with the joined string only, as before.
	 */
	public static function makeStorageContentBlock(string $title, string $valueLabel = ''): ContentBlock
	{
		$title = trim($title);
		$text = $title !== '' ? $title : (string)Loc::getMessage('BIZPROC_STORAGE_ACTIVITY_CONTENT_BLOCK_EMPTY');

		// The summary gets the title, not the joined string: a labelled placeholder would read as
		// "Storage: no storage selected", and for the node creating one - as a plain untruth.
		return new ContentBlock($text, self::makeContentBlockItems($valueLabel, $title));
	}

	/**
	 * Label of the storage name for the settings summary of a node that reads, writes or deletes items.
	 *
	 * Resolved here rather than at the call sites: Loc loads a language file lazily by the file asking
	 * for the phrase, so this one is only reachable from the file its lang folder belongs to.
	 */
	private static function resolveStorageContentBlockLabel(): string
	{
		$language = Loc::getCurrentLang();
		if (isset(self::$storageContentBlockLabelByLanguage[$language]))
		{
			return self::$storageContentBlockLabelByLanguage[$language];
		}

		$label = (string)Loc::getMessage('BIZPROC_STORAGE_ACTIVITY_CONTENT_BLOCK_LABEL_STORAGE');
		if ($label !== '')
		{
			self::$storageContentBlockLabelByLanguage[$language] = $label;
		}

		return $label;
	}

	/**
	 * @return list<ContentBlockItem>
	 */
	private static function makeContentBlockItems(string $label, string $value): array
	{
		// Labels are activity property map phrases, and such a phrase may end with a colon; the
		// settings panel puts its own separator between the label and the value.
		$label = trim(rtrim(trim($label), ':'));
		$value = trim($value);

		// Either half missing leaves nothing worth showing: without a label the value has no name, and
		// without a value there is nothing to name.
		if ($label === '' || $value === '')
		{
			return [];
		}

		return [new ContentBlockItem($label, $value)];
	}

	public static function getFilteringFieldsMapByStorageIds(array $storageIds): array
	{
		$result = [];

		$fieldsByStorage = [];
		if (self::isMigrationCompleted())
		{
			$allDynamicFields = (new StorageFieldProvider())->getByStorageIds($storageIds);
			foreach ($allDynamicFields as $field)
			{
				$fieldsByStorage[$field->getStorageId()][] = $field;
			}
		}

		foreach ($storageIds as $id)
		{
			$result[$id] = self::getFilteringFieldsMap(
				$id,
				new StorageFieldCollection(...($fieldsByStorage[$id] ?? [])),
			);
		}

		return $result;
	}

	private static function isMigrationCompleted(): bool
	{
		return StorageEavMigrationPhase::getCurrent() !== StorageEavMigrationPhase::DualWriteJsonRead;
	}

	public static function isOrmFilterEmpty(array $filter): bool
	{
		foreach ($filter as $key => $filterPart)
		{
			if ($key === 'LOGIC')
			{
				continue;
			}

			if (!empty($filterPart))
			{
				return false;
			}
		}

		return true;
	}
}
