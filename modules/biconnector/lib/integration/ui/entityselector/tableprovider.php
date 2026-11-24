<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Public\Provider\RowRule\RowRuleAccessProvider;
use Bitrix\BIConnector\Public\Provider\RowRule\RowRuleFieldProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\EntityError;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * Tables of BI Builder, system and custom: for the row rule popup and for the table group popup.
 */
class TableProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-table';
	public const ERROR_LOAD_FAILED = 'ROW_RULE_LOAD_FAILED';

	private const TAB_ID = 'tables';

	// The icons of the dashboard list, the same pair the table groups use.
	private const SYSTEM_TABLE_ICON = '/bitrix/images/biconnector/database-connections/system.svg';
	private const CUSTOM_TABLE_ICON = '/bitrix/images/biconnector/superset-dashboard-selector/icon-type-custom.webp';

	private readonly RowRuleFieldProvider $catalog;
	private readonly RowRuleAccessProvider $accessProvider;
	private readonly \Closure $tableRightsChecker;

	/**
	 * @param array $options Options of the dialog.
	 * @param RowRuleFieldProvider|null $catalog Tables and fields of BI Builder.
	 * @param RowRuleAccessProvider|null $accessProvider
	 * @param \Closure|null $tableRightsChecker Whether the current user arranges the table groups.
	 */
	public function __construct(
		array $options = [],
		?RowRuleFieldProvider $catalog = null,
		?RowRuleAccessProvider $accessProvider = null,
		?\Closure $tableRightsChecker = null,
	)
	{
		parent::__construct();

		$this->catalog = $catalog ?? new RowRuleFieldProvider();
		$this->accessProvider = $accessProvider ?? new RowRuleAccessProvider();
		$this->tableRightsChecker = $tableRightsChecker ?? self::canArrangeTableGroups(...);
	}

	/**
	 * Whoever manages row rules or arranges the table groups picks tables.
	 */
	public function isAvailable(): bool
	{
		return $this->accessProvider->canManage((int)CurrentUser::get()->getId()) || ($this->tableRightsChecker)();
	}

	/**
	 * The same rights as the table group controller checks before it gives the catalog to its popup:
	 * the access to BI Builder of its pre-filter and the right to arrange the table groups.
	 * The groups are arranged in either state of the table axis, the rule tells which right arranges them.
	 */
	private static function canArrangeTableGroups(): bool
	{
		$accessController = AccessController::getCurrent();

		return $accessController->check(ActionDictionary::ACTION_BIC_ACCESS)
			&& $accessController->check(ActionDictionary::ACTION_BIC_TABLE_GROUP_MANAGE)
		;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addItems($this->makeItems($this->loadTables($dialog)));
		$dialog->addTab(new Tab([
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('BICONNECTOR_TABLE_PROVIDER_TAB_LABEL'),
		]));
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$query = $searchQuery->getQuery();
		$found = array_filter(
			$this->loadTables($dialog),
			static fn(array $table): bool => self::matches($query, $table['name'], $table['code']),
		);

		$dialog->addItems($this->makeItems($found));
	}

	public function getItems(array $ids): array
	{
		$codes = array_flip(array_map('strval', $ids));
		$requested = array_filter(
			$this->loadTables(null),
			static fn(array $table): bool => isset($codes[$table['code']]),
		);

		return $this->makeItems($requested);
	}

	/**
	 * @return array{code: string, name: string, type: string}[]
	 */
	private function loadTables(?Dialog $dialog): array
	{
		return $this->load(fn(): array => $this->catalog->getTables()['items'], $dialog) ?? [];
	}

	/**
	 * @param array{code: string, name: string, type: string}[] $tables
	 *
	 * @return Item[]
	 */
	private function makeItems(array $tables): array
	{
		$items = [];
		foreach ($tables as $table)
		{
			$items[] = new Item([
				'id' => $table['code'],
				'entityId' => self::ENTITY_ID,
				'title' => $table['name'],
				'subtitle' => $table['code'],
				'tabs' => self::TAB_ID,
				'saveable' => false,
				'avatar' => $table['type'] === TableGroupService::TABLE_TYPE_CUSTOM
					? self::CUSTOM_TABLE_ICON
					: self::SYSTEM_TABLE_ICON,
				'avatarOptions' => [
					'borderRadius' => '4px',
				],
				'customData' => [
					'type' => $table['type'],
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
				(string)Loc::getMessage('BICONNECTOR_TABLE_PROVIDER_ERROR_LOAD_FAILED'),
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
