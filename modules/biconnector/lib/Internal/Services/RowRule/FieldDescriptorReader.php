<?php

namespace Bitrix\BIConnector\Internal\Services\RowRule;

use Bitrix\BIConnector\Access\Service\TableCatalogService;
use Bitrix\BIConnector\DataSource\FieldKind;
use Bitrix\BIConnector\Manager;

/**
 * Reads the fields of a table description for row rules.
 *
 * Type, kind and owner of a field are never stored in a rule: they come from the current description,
 * so a rule is checked against the table as it is now. Each table is read once per reader.
 */
final class FieldDescriptorReader
{
	private const SERVICE_ID = 'superset';
	private const DEFAULT_TYPE = 'string';
	private const SUPPORTED_TYPES = ['int', 'double', 'string', 'date', 'datetime'];
	private const KNOWN_KINDS = ['', FieldKind::EMPLOYEE, FieldKind::DEPARTMENT, FieldKind::DICTIONARY];

	/** @var \Closure(string): (array{NAME: string, FIELDS: array}|null) */
	private readonly \Closure $tableLoader;

	/** @var array<string, array{name: string, fields: array<string, FieldDescriptor>}|null> */
	private array $tables = [];

	/**
	 * @param \Closure|null $tableLoader Takes a table code and returns `['NAME' => string, 'FIELDS' => array]`
	 *     of the table or null when the catalog has no such table.
	 */
	public function __construct(?\Closure $tableLoader = null)
	{
		$this->tableLoader = $tableLoader ?? self::loadFromCatalog(...);
	}

	public function hasTable(string $tableCode): bool
	{
		return $this->getTable($tableCode) !== null;
	}

	public function getTableName(string $tableCode): ?string
	{
		return $this->getTable($tableCode)['name'] ?? null;
	}

	/**
	 * @param string $tableCode
	 *
	 * @return FieldDescriptor[] In the order of the table description.
	 */
	public function getFields(string $tableCode): array
	{
		return array_values($this->getTable($tableCode)['fields'] ?? []);
	}

	public function getField(string $tableCode, string $fieldCode): ?FieldDescriptor
	{
		return $this->getTable($tableCode)['fields'][$fieldCode] ?? null;
	}

	/**
	 * @param string $tableCode
	 *
	 * @return array{name: string, fields: array<string, FieldDescriptor>}|null
	 */
	private function getTable(string $tableCode): ?array
	{
		if (!array_key_exists($tableCode, $this->tables))
		{
			$this->tables[$tableCode] = $this->buildTable(($this->tableLoader)($tableCode));
		}

		return $this->tables[$tableCode];
	}

	private function buildTable(?array $rawTable): ?array
	{
		if ($rawTable === null)
		{
			return null;
		}

		$rawFields = is_array($rawTable['FIELDS'] ?? null) ? $rawTable['FIELDS'] : [];
		$fields = [];
		foreach ($rawFields as $code => $info)
		{
			$fields[(string)$code] = $this->buildField((string)$code, is_array($info) ? $info : [], $rawFields);
		}

		return [
			'name' => (string)($rawTable['NAME'] ?? ''),
			'fields' => $fields,
		];
	}

	private function buildField(string $code, array $info, array $rawFields): FieldDescriptor
	{
		$type = (string)($info['FIELD_TYPE_EX'] ?? $info['FIELD_TYPE'] ?? self::DEFAULT_TYPE);
		$kind = $info[FieldKind::KEY_KIND] ?? '';
		$owner = $info[FieldKind::KEY_OWNER] ?? null;
		$owner = is_string($owner) && $owner !== '' ? $owner : null;

		return new FieldDescriptor(
			$code,
			(string)($info['FIELD_DESCRIPTION'] ?? $code),
			$type,
			is_string($kind) ? $kind : '',
			$owner,
			is_string($kind) && $this->isSupported($type, $kind, $owner, $rawFields),
		);
	}

	private function isSupported(string $type, string $kind, ?string $owner, array $rawFields): bool
	{
		if (!in_array($type, self::SUPPORTED_TYPES, true) || !in_array($kind, self::KNOWN_KINDS, true))
		{
			return false;
		}

		if ($kind === FieldKind::EMPLOYEE)
		{
			return $type === 'int';
		}

		if ($kind === FieldKind::DEPARTMENT)
		{
			return $owner !== null && ($rawFields[$owner][FieldKind::KEY_KIND] ?? null) === FieldKind::EMPLOYEE;
		}

		return true;
	}

	private static function loadFromCatalog(string $tableCode): ?array
	{
		$titles = TableCatalogService::getTableTitles();
		if (!isset($titles[$tableCode]))
		{
			return null;
		}

		$connector = Manager::getInstance()->createService(self::SERVICE_ID)?->getDataSourceConnector($tableCode);

		return [
			'NAME' => $titles[$tableCode],
			'FIELDS' => $connector?->rawInfo['FIELDS'] ?? [],
		];
	}
}
