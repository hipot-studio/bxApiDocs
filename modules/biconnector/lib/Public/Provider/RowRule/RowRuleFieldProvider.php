<?php

namespace Bitrix\BIConnector\Public\Provider\RowRule;

use Bitrix\BIConnector\Access\Service\TableCatalogService;
use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Internal\Services\RowRule\FieldDescriptorReader;
use Bitrix\BIConnector\Internal\Services\RowRule\RowRuleError;
use Bitrix\BIConnector\Internal\Services\RowRule\TokenDictionary;
use Bitrix\Main\Result;

/**
 * Tables and fields a row rule may be set on, for the rule popup.
 */
class RowRuleFieldProvider
{
	/** @var \Closure(): array<string, string> */
	private readonly \Closure $titlesLoader;

	/** @var \Closure(string): bool */
	private readonly \Closure $userTableChecker;

	/**
	 * @param \Closure|null $titlesLoader Returns titles of the catalog tables by code;
	 *     the catalog of BI Builder when null.
	 * @param \Closure|null $tableLoader Loader of table descriptions for FieldDescriptorReader;
	 *     the catalog of BI Builder when null.
	 * @param \Closure|null $userTableChecker Tells a dataset of an external source by the table code;
	 *     the catalog of BI Builder when null.
	 */
	public function __construct(
		?\Closure $titlesLoader = null,
		private readonly ?\Closure $tableLoader = null,
		?\Closure $userTableChecker = null,
	)
	{
		$this->titlesLoader = $titlesLoader ?? TableCatalogService::getTableTitles(...);
		$this->userTableChecker = $userTableChecker ?? TableCatalogService::isUserTable(...);
	}

	/**
	 * @return array{items: array{code: string, name: string, type: string}[]} Sorted by name.
	 */
	public function getTables(): array
	{
		$items = [];
		foreach (($this->titlesLoader)() as $code => $name)
		{
			$code = (string)$code;
			$items[] = [
				'code' => $code,
				'name' => is_string($name) && $name !== '' ? $name : $code,
				'type' => ($this->userTableChecker)($code)
					? TableGroupService::TABLE_TYPE_CUSTOM
					: TableGroupService::TABLE_TYPE_SYSTEM,
			];
		}

		usort(
			$items,
			static fn(array $a, array $b): int =>
				strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))
				?: strcmp($a['code'], $b['code'])
		);

		return ['items' => $items];
	}

	/**
	 * @param string $tableCode
	 *
	 * @return Result Data: `table` `{code, name}` and `fields` `{code, name, type, kind, ownerField, tokens}[]`
	 *     in the order of the table description; ROW_RULE_TABLE_NOT_FOUND when the catalog has no such table.
	 */
	public function getFields(string $tableCode): Result
	{
		$result = new Result();

		$reader = new FieldDescriptorReader($this->tableLoader);
		if ($tableCode === '' || !$reader->hasTable($tableCode))
		{
			return $result->addError(RowRuleError::create(RowRuleError::TABLE_NOT_FOUND, ['field' => 'tableCode']));
		}

		$fields = [];
		foreach ($reader->getFields($tableCode) as $field)
		{
			if (!$field->isSupported)
			{
				continue;
			}

			$tokens = [];
			foreach (TokenDictionary::getAllowed($field->kind) as $code)
			{
				$tokens[] = [
					'code' => $code,
					'title' => TokenDictionary::getTitle($code),
				];
			}

			$fields[] = [
				'code' => $field->code,
				'name' => $field->name,
				'type' => $field->type,
				'kind' => $field->kind,
				'ownerField' => $field->ownerField,
				'tokens' => $tokens,
			];
		}

		return $result->setData([
			'table' => [
				'code' => $tableCode,
				'name' => $reader->getTableName($tableCode) ?: $tableCode,
			],
			'fields' => $fields,
		]);
	}
}
