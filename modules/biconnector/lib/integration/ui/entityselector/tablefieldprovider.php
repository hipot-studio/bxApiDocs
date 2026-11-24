<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Public\Provider\RowRule\RowRuleAccessProvider;
use Bitrix\BIConnector\Public\Provider\RowRule\RowRuleFieldProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\EntityError;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * Fields of one table of BI Builder with their type and kind; the `tableCode` option names the table.
 *
 * The custom data of an item carries what the popup needs to offer the values: the type and the kind of the field,
 * the employee field a department belongs to and the tokens of the kind.
 */
class TableFieldProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-table-field';
	public const ERROR_LOAD_FAILED = 'ROW_RULE_LOAD_FAILED';

	private const TAB_ID = 'fields';

	private readonly RowRuleFieldProvider $catalog;
	private readonly RowRuleAccessProvider $accessProvider;

	/**
	 * @param array $options Options of the dialog: `tableCode` - the table whose fields are offered.
	 * @param RowRuleFieldProvider|null $catalog
	 * @param RowRuleAccessProvider|null $accessProvider
	 */
	public function __construct(
		array $options = [],
		?RowRuleFieldProvider $catalog = null,
		?RowRuleAccessProvider $accessProvider = null,
	)
	{
		parent::__construct();

		$this->catalog = $catalog ?? new RowRuleFieldProvider();
		$this->accessProvider = $accessProvider ?? new RowRuleAccessProvider();

		$tableCode = $options['tableCode'] ?? null;
		$this->options['tableCode'] = is_string($tableCode) ? $tableCode : '';
	}

	/**
	 * The fields serve the row rules alone.
	 */
	public function isAvailable(): bool
	{
		return $this->accessProvider->canManage((int)CurrentUser::get()->getId());
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addItems($this->makeItems($this->loadFields($dialog)));
		$dialog->addTab(new Tab([
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('BICONNECTOR_TABLE_FIELD_PROVIDER_TAB_LABEL'),
		]));
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$query = $searchQuery->getQuery();
		$found = array_filter(
			$this->loadFields($dialog),
			static fn(array $field): bool => self::matches($query, $field['name'], $field['code']),
		);

		$dialog->addItems($this->makeItems($found));
	}

	public function getItems(array $ids): array
	{
		$codes = array_flip(array_map('strval', $ids));
		$requested = array_filter(
			$this->loadFields(null),
			static fn(array $field): bool => isset($codes[$field['code']]),
		);

		return $this->makeItems($requested);
	}

	/**
	 * @return array{code: string, name: string, type: string, kind: string, ownerField: ?string, tokens: array}[]
	 *     In the order of the table description.
	 */
	private function loadFields(?Dialog $dialog): array
	{
		$tableCode = (string)$this->getOption('tableCode', '');

		$result = $this->load(fn(): Result => $this->catalog->getFields($tableCode), $dialog);
		if (!$result instanceof Result)
		{
			return [];
		}

		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$dialog?->addError(new EntityError(self::ENTITY_ID, $error->getMessage(), $error->getCode()));
			}

			return [];
		}

		return $result->getData()['fields'];
	}

	/**
	 * @return Item[]
	 */
	private function makeItems(array $fields): array
	{
		$items = [];
		foreach ($fields as $field)
		{
			$items[] = new Item([
				'id' => $field['code'],
				'entityId' => self::ENTITY_ID,
				'title' => $field['name'],
				'subtitle' => $field['code'],
				'tabs' => self::TAB_ID,
				'saveable' => false,
				'customData' => [
					'type' => $field['type'],
					'kind' => $field['kind'],
					'ownerField' => $field['ownerField'],
					'tokens' => $field['tokens'],
				],
			]);
		}

		return $items;
	}

	/**
	 * Runs a read of the catalog; an unexpected failure goes to the log and, when there is a dialog, to its errors.
	 *
	 * @return mixed The value of the work, null when it failed.
	 */
	private function load(callable $work, ?Dialog $dialog): mixed
	{
		try
		{
			return $work();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			$dialog?->addError(new EntityError(
				self::ENTITY_ID,
				(string)Loc::getMessage('BICONNECTOR_TABLE_FIELD_PROVIDER_ERROR_LOAD_FAILED'),
				self::ERROR_LOAD_FAILED,
			));

			return null;
		}
	}

	/**
	 * @param string $query Text of the search.
	 * @param string ...$texts Name and code of an item.
	 */
	private static function matches(string $query, string ...$texts): bool
	{
		$query = mb_strtolower(trim($query));
		if ($query === '')
		{
			return true;
		}

		foreach ($texts as $text)
		{
			if (mb_strpos(mb_strtolower($text), $query) !== false)
			{
				return true;
			}
		}

		return false;
	}
}
