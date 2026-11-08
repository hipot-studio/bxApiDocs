<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Provider;

use Bitrix\Bizproc\BaseType\Value;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Internal\Service\DocumentField\FieldValueFormatter;
use Bitrix\Bizproc\Internal\Service\StorageField\Converter\ValueFieldConverter;
use Bitrix\Bizproc\Public\DataView\Dto\ExtractQuery;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor;
use Bitrix\Bizproc\Public\DataView\Dto\SourceField;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceSchema;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Interface\PresentableProvider;
use Bitrix\Bizproc\Public\DataView\Interface\SourceAvailabilityCapableProvider;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;

final class StorageDataSourceProvider implements
	DataSourceProvider,
	PresentableProvider,
	SourceAvailabilityCapableProvider
{
	public const MODULE_ID = 'bizproc';
	public const ENTITY = 'storage';

	private const HEADER_SELECT = [
		'ID',
		'STORAGE_ID',
		'CREATED_BY',
		'UPDATED_BY',
		'CREATED_TIME',
		'UPDATED_TIME',
		'DOCUMENT_ID',
		'WORKFLOW_ID',
		'TEMPLATE_ID',
	];

	private const KNOWN_FIELD_TYPES = [
		FieldType::BOOL,
		FieldType::DATE,
		FieldType::DATETIME,
		FieldType::DOUBLE,
		FieldType::INT,
		FieldType::SELECT,
		FieldType::INTERNALSELECT,
		FieldType::STRING,
		FieldType::TEXT,
		FieldType::USER,
		FieldType::TIME,
	];

	/** @var array<int, array<string, FieldValueFormatter>> */
	private array $fieldFormattersByStorageType = [];

	public function getModuleId(): string
	{
		return self::MODULE_ID;
	}

	public function getAvailableSources(int $actorId): array
	{
		$filter = [];
		$closedStorageTypeIds = (new TemplateAccessService())->getClosedStorageTypeIds($actorId);
		if ($closedStorageTypeIds !== [])
		{
			$filter['!ID'] = $closedStorageTypeIds;
		}

		$descriptors = [];
		$types = (new StorageTypeProvider())->getStoragesByFilter($filter, ['ID', 'TITLE', 'CODE']);
		foreach ($types as $type)
		{
			$descriptors[] = new SourceDescriptor(
				module: self::MODULE_ID,
				entity: self::ENTITY,
				title: (string)($type->getTitle() ?? ''),
				requiresParams: true,
				bounded: true,
				params: ['storageTypeId' => $type->getId()],
			);
		}

		return $descriptors;
	}

	/**
	 * The catalog holds every storage type the actor may reach, so presence in it is the existence of the
	 * type named by the source together with the right to edit it; the template context does not narrow it.
	 */
	public function isSourceAvailable(SourceRef $source, int $actorId, ?int $templateId): bool
	{
		$storageTypeId = $this->resolveStorageTypeId($source);

		return $storageTypeId > 0
			&& (new StorageTypeProvider())->exists($storageTypeId)
			&& (new TemplateAccessService())->canEditStorage($storageTypeId, $actorId)
		;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	public function getSourceSchema(SourceRef $source): SourceSchema
	{
		$storageTypeId = $this->resolveStorageTypeId($source);
		$this->assertTypeAvailable($storageTypeId);

		$fields = [];
		$collection = Container::getStorageFieldRepository()?->getByStorageId($storageTypeId, ['*']);
		foreach (($collection ?? []) as $field)
		{
			if ($field->getType() === FieldType::FILE)
			{
				continue;
			}

			$multiple = (bool)$field->getMultiple();
			$type = $this->normalizeFieldType($field->getType());
			$fields[] = new SourceField(
				code: (string)$field->getCode(),
				title: (string)($field->getName() ?? $field->getCode()),
				type: $type,
				multiple: $multiple,
				joinable: !$multiple,
				presentable: FieldValueFormatter::isPresentableType($type),
			);
		}

		return new SourceSchema($fields);
	}

	/**
	 * @return iterable<array<string, scalar|null|array>>
	 * @throws SourceUnavailableException
	 */
	public function extract(SourceRef $source, ExtractQuery $query, int $actorId): iterable
	{
		$storageTypeId = $this->resolveStorageTypeId($source);
		$this->assertTypeAvailable($storageTypeId);
		if (!(new TemplateAccessService())->canEditStorage($storageTypeId, $actorId))
		{
			throw SourceUnavailableException::sourceNotAvailableToActor(self::MODULE_ID, self::ENTITY);
		}

		$schema = $this->getSourceSchema($source);

		$this->assertRequestedFieldsExist($schema, $query);

		if ($query->limit <= 0)
		{
			return;
		}

		$filter = [];

		if ($query->hasDateWindow())
		{

			$filter['CREATED_WITHIN'] = [
				'FROM' => $query->getDateFrom(),
				'TO' => $query->getDateTo(),
			];
		}

		if ($query->hasKeyIn() && $query->getKeyValues() !== [])
		{

			$filter['@' . $query->getKeyField()] = $query->getKeyValues();
		}

		// The limit cuts the source off, so the order has to be deterministic: without it the DBMS
		// may return a different slice on every run and paged preview would skip and repeat rows.
		$collection = (new StorageItemProvider($storageTypeId))->getItems([
			'filter' => $filter,
			'order' => ['CREATED_TIME' => 'ASC', 'ID' => 'ASC'],
			'limit' => $query->limit,
			'select' => $this->buildItemsSelect($query),
		]);

		foreach (($collection ?? []) as $item)
		{
			yield $this->projectRow($item, $query->select, $schema);
		}
	}

	/**
	 * With an explicit select loads only the requested dynamic fields; header columns stay
	 * in the select because the item mapper requires them.
	 *
	 * @return string[]
	 */
	private function buildItemsSelect(ExtractQuery $query): array
	{
		if ($query->select === [])
		{
			return ['*'];
		}

		return array_merge(self::HEADER_SELECT, array_values(array_unique($query->select)));
	}

	private function assertRequestedFieldsExist(SourceSchema $schema, ExtractQuery $query): void
	{
		$requestedCodes = $query->select;

		$keyField = $query->hasKeyIn() ? $query->getKeyField() : null;
		if ($keyField !== null && $keyField !== '')
		{
			$requestedCodes[] = $keyField;
		}

		if ($requestedCodes === [])
		{
			return;
		}

		foreach ($requestedCodes as $code)
		{
			if ($schema->getField((string)$code) === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved((string)$code);
			}
		}
	}

	public function getRelations(SourceRef $source): array
	{
		return [];
	}

	/**
	 * @param iterable<int|string, array<string, mixed>> $rows
	 * @return iterable<int|string, array<string, string>>
	 */
	public function present(SourceRef $source, iterable $rows, int $actorId = 0): iterable
	{
		$rows = is_array($rows) ? $rows : iterator_to_array($rows, true);
		if ($rows === [])
		{
			return [];
		}

		$formatters = $this->getFieldFormatters($this->resolveStorageTypeId($source));
		if ($formatters === [])
		{
			return [];
		}

		FieldValueFormatter::prefetchUsers($rows);

		$presented = [];
		foreach ($rows as $rowKey => $row)
		{
			$labels = [];
			foreach ((array)$row as $code => $value)
			{
				$formatter = $formatters[(string)$code] ?? null;
				if ($formatter !== null)
				{
					$labels[(string)$code] = $formatter->format($value);
				}
			}

			$presented[$rowKey] = $labels;
		}

		return $presented;
	}

	/**
	 * The presenter calls present() once per batch of unique field codes, so a single preview render
	 * hits the same storage type more than once; the instance lives for one request.
	 *
	 * @return array<string, FieldValueFormatter>
	 */
	private function getFieldFormatters(int $storageTypeId): array
	{
		return $this->fieldFormattersByStorageType[$storageTypeId] ??= $this->buildFieldFormatters($storageTypeId);
	}

	/**
	 * One formatter per storage field, built from a single field selection: formatting a user or a
	 * date reaches out for names and site settings, so it must not be rebuilt per cell.
	 *
	 * @return array<string, FieldValueFormatter>
	 */
	private function buildFieldFormatters(int $storageTypeId): array
	{
		if ($storageTypeId <= 0)
		{
			return [];
		}

		$formatters = [];
		$collection = Container::getStorageFieldRepository()?->getByStorageId($storageTypeId, ['*']);
		foreach (($collection ?? []) as $field)
		{
			$property = $field->toProperty();
			$property['Type'] = $this->normalizeFieldType($field->getType());

			$code = (string)$field->getCode();
			$formatter = FieldValueFormatter::forProperty($property);
			if ($code !== '' && $formatter !== null)
			{
				$formatters[$code] = $formatter;
			}
		}

		return $formatters;
	}

	private function resolveStorageTypeId(SourceRef $source): int
	{
		$raw = $source->getParam('storageTypeId');

		return is_numeric($raw) ? (int)$raw : 0;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	private function assertTypeAvailable(int $storageTypeId): void
	{
		if ($storageTypeId <= 0 || !(new StorageTypeProvider())->exists($storageTypeId))
		{
			throw SourceUnavailableException::sourceTypeRemoved($storageTypeId);
		}
	}

	private function normalizeFieldType(?string $storedType): string
	{
		$type = (string)$storedType;

		return in_array($type, self::KNOWN_FIELD_TYPES, true) ? $type : FieldType::STRING;
	}

	/**
	 * @param string[] $select
	 * @return array<string, scalar|null|array>
	 */
	private function projectRow(StorageItem $item, array $select, SourceSchema $schema): array
	{
		$row = $item->toArray();
		if ($select !== [])
		{
			$projected = [];
			foreach ($select as $code)
			{
				$projected[$code] = $row[$code] ?? null;
			}

			$projected['id'] = $row['id'] ?? null;
			$row = $projected;
		}

		return $this->normalizeRow($row, $schema);
	}

	private function normalizeRow(array $row, SourceSchema $schema): array
	{
		$normalized = [];
		foreach ($row as $code => $value)
		{
			$field = $schema->getField((string)$code);
			$normalized[$code] = $field === null
				? $this->normalizeSystemValue($value)
				: $this->normalizeFieldValue($value, $field)
			;
		}

		return $normalized;
	}

	private function normalizeFieldValue(mixed $value, SourceField $field): mixed
	{
		if (!$field->multiple && ($value === null || $value === ''))
		{
			return $value;
		}

		$normalized = ValueFieldConverter::toReadValues($value, $field->type, $field->multiple);

		return $field->multiple ? $normalized : ($normalized[0] ?? null);
	}

	private function normalizeSystemValue(mixed $value): mixed
	{
		if (is_array($value))
		{
			return array_map(fn (mixed $element): mixed => $this->normalizeSystemValue($element), $value);
		}

		if ($value instanceof Value\Date)
		{
			$value = $value->toSystemObject();
		}

		if ($value instanceof DateTime)
		{
			return $value->format('Y-m-d H:i:s');
		}

		if ($value instanceof Date)
		{
			return $value->format('Y-m-d');
		}

		return $value;
	}
}
