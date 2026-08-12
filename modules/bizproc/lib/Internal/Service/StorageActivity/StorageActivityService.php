<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageActivity;

use Bitrix\Bizproc\Activity\Dto\ContentBlock;
use Bitrix\Bizproc\Activity\Dto\ContentBlockContext;
use Bitrix\Bizproc\FieldType;
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

	public static function getFilteringFieldsMap(int $storageId, ?array $prefetchedFields = null): array
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
		$type = $provider->getType(['CODE' => $storageCode], ['ID']);

		return self::$storageIdByCodeCache[$storageCode] = (int)$type?->getId();
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

	public static function getContentBlock(array $properties, ?ContentBlockContext $context = null): ?ContentBlock
	{
		$storageId = isset($properties['StorageId']) ? (int)$properties['StorageId'] : 0;
		$storageCode = (string)($properties['StorageCode'] ?? '');
		if ($storageId <= 0 && $storageCode !== '')
		{
			$storageId = self::resolveStorageId(null, $storageCode);
		}

		if ($storageId > 0)
		{
			return self::makeStorageContentBlock(self::resolveStorageTitleById($storageId));
		}

		if ($storageCode !== '')
		{
			$dynamicTitle = $context?->scope?->resolve(self::CONTENT_BLOCK_SCOPE_NAMESPACE, $storageCode);
			if ($dynamicTitle !== null)
			{
				return self::makeStorageContentBlock($dynamicTitle);
			}
		}

		return self::makeStorageContentBlock('');
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

	public static function makeStorageContentBlock(string $title): ContentBlock
	{
		$title = trim($title);

		return new ContentBlock(
			$title !== '' ? $title : (string)Loc::getMessage('BIZPROC_STORAGE_ACTIVITY_CONTENT_BLOCK_EMPTY'),
		);
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
			$result[$id] = self::getFilteringFieldsMap($id, $fieldsByStorage[$id] ?? []);
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
