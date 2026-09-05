<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\UI\EntitySelector;

use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * SEL-01: entity selector provider for active portal mailboxes.
 * Used by the shared signature assignment dialog ("specific mailboxes" mode).
 *
 * isAvailable() mirrors the SharedSignatureAccess gate filter — the tariff plus the right to
 * manage employee mailboxes: the load endpoint is reachable by any authorized user, so without
 * this gate the provider would expose every mailbox email on the portal to whoever may merely
 * see their grid.
 */
class MailboxProvider extends BaseProvider
{
	public const PROVIDER_ENTITY_ID = 'mail_mailbox';
	private const ITEMS_TAB_LIMIT = 20;
	private const SEARCH_ITEMS_LIMIT = 50;

	public function __construct(array $options = [])
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		if ((int)CurrentUser::get()->getId() <= 0)
		{
			return false;
		}

		return LicenseManager::isMailboxManagementEnabled()
			&& MailAccess::hasCurrentUserAccessToMailboxManagement();
	}

	/**
	 * @param array $ids mailbox IDs (preselect / round-trip)
	 * @return Item[]
	 */
	public function getItems(array $ids): array
	{
		$ids = array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0);
		if (empty($ids))
		{
			return [];
		}

		return $this->buildItems($this->fetchMailboxes(['@ID' => $ids]));
	}

	public function fillDialog(Dialog $dialog): void
	{
		$this->addMailboxTab($dialog);

		$entity = $dialog->getEntity(self::PROVIDER_ENTITY_ID);
		$entity?->setDynamicSearch();

		foreach ($this->buildItems($this->fetchMailboxes([], self::ITEMS_TAB_LIMIT)) as $item)
		{
			$dialog->addItem($item);
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$query = trim($searchQuery->getQuery());
		if ($query === '')
		{
			return;
		}

		$rows = $this->fetchMailboxes(
			[
				[
					'LOGIC' => 'OR',
					'%EMAIL' => $query,
					'%NAME' => $query,
				],
			],
			self::SEARCH_ITEMS_LIMIT,
		);

		foreach ($this->buildItems($rows) as $item)
		{
			$dialog->addItem($item);
		}
	}

	/**
	 * Fetches active mailboxes from the DB. Extracted for test overrides.
	 *
	 * @return array<array{ID: int|string, EMAIL: string|null, NAME: string|null}>
	 */
	protected function fetchMailboxes(array $filter, ?int $limit = null): array
	{
		$parameters = [
			'select' => ['ID', 'EMAIL', 'NAME'],
			'filter' => array_merge(['=ACTIVE' => 'Y'], $filter),
			'order' => ['ID' => 'ASC'],
		];

		if ($limit !== null)
		{
			$parameters['limit'] = $limit;
		}

		return MailboxTable::getList($parameters)->fetchAll();
	}

	/**
	 * @return Item[]
	 */
	private function buildItems(array $rows): array
	{
		return array_map(fn(array $row): Item => $this->buildItem($row), array_values($rows));
	}

	private function buildItem(array $row): Item
	{
		$email = (string)($row['EMAIL'] ?? '');
		$name = trim((string)($row['NAME'] ?? ''));
		$title = ($name !== '' ? $name : $email);

		return new Item([
			'id' => (int)$row['ID'],
			'entityId' => self::PROVIDER_ENTITY_ID,
			'tabs' => self::PROVIDER_ENTITY_ID,
			'title' => $title,
			'subtitle' => ($name !== '' ? $email : null),
			'customData' => [
				'entityType' => self::PROVIDER_ENTITY_ID,
				'mailboxId' => (int)$row['ID'],
				'email' => $email,
			],
		]);
	}

	private static function addMailboxTab(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::PROVIDER_ENTITY_ID,
			'title' => Loc::getMessage('MAIL_MAILBOX_PROVIDER_TAB_TITLE'),
			'header' => Loc::getMessage('MAIL_MAILBOX_PROVIDER_TAB_HEADER'),
			'icon' => [
				'default' => 'o-mail',
				'selected' => 'o-mail',
			],
		]));
	}
}
