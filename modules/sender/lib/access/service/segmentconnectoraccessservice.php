<?php

namespace Bitrix\Sender\Access\Service;

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Main\Access\Event\EventDictionary;
use Bitrix\Main\Loader;
use Bitrix\Sender\Access\AccessController;
use Bitrix\Sender\Access\ActionDictionary;
use Bitrix\Sender\Integration\Crm\Connectors\Client;
use Bitrix\Sender\Security;

/**
 * The single rule of which segment sources (connector + deal categories and stages) a user may use.
 */
final class SegmentConnectorAccessService
{
	public const CONNECTOR_ACTIONS = [
		'crm_lead' => ActionDictionary::ACTION_SEGMENT_LEAD_EDIT,
		'crm_client' => ActionDictionary::ACTION_SEGMENT_CLIENT_EDIT,
		'contact_list' => ActionDictionary::ACTION_SEGMENT_CLIENT_PERSONAL_EDIT,
	];

	private const CLIENT_CODE = 'crm_client';
	private const DEAL_STAGE_ID = 'DEAL_STAGE_ID';
	private const DEFAULT_DEAL_CATEGORY_ID = '0';
	private const WITHOUT_DEAL_CATEGORY_ID = '';

	private int $userId;

	/** @var array<string, bool> */
	private array $connectorAccess = [];

	private bool $isDealCategoriesResolved = false;

	/** @var string[]|null */
	private ?array $allowedDealCategoryIds = null;

	public function __construct(int $userId)
	{
		$this->userId = $userId;
		Security\Access::registerEvent(EventDictionary::EVENT_ON_AFTER_CHECK);
	}

	public function canUseConnector(string $code): bool
	{
		$action = self::CONNECTOR_ACTIONS[$code] ?? null;
		if ($action === null)
		{
			return true;
		}

		return $this->connectorAccess[$code] ??= AccessController::getInstance($this->userId)->check($action);
	}

	/**
	 * @return string[]|null Deal category IDs as strings including '' (without deal), null if all are allowed.
	 */
	public function getAllowedDealCategoryIds(): ?array
	{
		if ($this->isDealCategoriesResolved)
		{
			return $this->allowedDealCategoryIds;
		}

		$this->isDealCategoriesResolved = true;
		$this->allowedDealCategoryIds = $this->resolveAllowedDealCategoryIds();

		return $this->allowedDealCategoryIds;
	}

	public function isEndpointAllowed(array $endpoint): bool
	{
		$code = (string)($endpoint['CODE'] ?? '');
		if (!$this->canUseConnector($code))
		{
			return false;
		}

		if ($code !== self::CLIENT_CODE)
		{
			return true;
		}

		// The connector selects nobody only without any key: empty values select every client.
		$fields = is_array($endpoint['FIELDS'] ?? null) ? $endpoint['FIELDS'] : [];
		if ($fields === [])
		{
			return true;
		}

		$allowed = $this->getAllowedDealCategoryIds();
		if ($allowed === null)
		{
			return true;
		}

		$categoryIds = $this->getRequestedDealCategoryIds($fields);
		$stageIds = $this->toIdList($fields[self::DEAL_STAGE_ID] ?? null);
		if ($categoryIds === null || $stageIds === null)
		{
			return false;
		}

		foreach ($categoryIds as $categoryId)
		{
			if (!in_array($categoryId, $allowed, true))
			{
				return false;
			}
		}

		foreach ($stageIds as $stageId)
		{
			if ($stageId === '')
			{
				continue;
			}

			if (!in_array(self::getStageDealCategoryId($stageId), $allowed, true))
			{
				return false;
			}
		}

		return true;
	}

	public function filterDealCategoryItems(array $items): array
	{
		return (new RoleDealCategoryService())->getFilteredDealCategories($this->userId, $items);
	}

	public function filterDealStageItems(array $items): array
	{
		$allowed = $this->getAllowedDealCategoryIds();
		if ($allowed === null)
		{
			return $items;
		}

		return array_filter(
			$items,
			static fn($stageId): bool => in_array(self::getStageDealCategoryId((string)$stageId), $allowed, true),
			ARRAY_FILTER_USE_KEY
		);
	}

	private function resolveAllowedDealCategoryIds(): ?array
	{
		if (AccessController::getInstance($this->userId)->isAdmin())
		{
			return null;
		}

		$categoryIds = [self::WITHOUT_DEAL_CATEGORY_ID];
		foreach ((new RoleDealCategoryService())->getAbleDealCategories($this->userId) as $row)
		{
			if ((int)$row['CATEGORY_ID'] === RoleDealCategoryService::ALL_CATEGORIES)
			{
				return null;
			}

			$categoryIds[] = (string)$row['CATEGORY_ID'];
		}

		return array_values(array_unique($categoryIds));
	}

	/**
	 * Without a category value the filter takes deals of every category of the portal.
	 *
	 * @return string[]|null Null if the value is not one the connector reads as the form sends it.
	 */
	private function getRequestedDealCategoryIds(array $fields): ?array
	{
		$value = $fields[Client::DEAL_CATEGORY_ID] ?? null;
		if (!self::isFilterValueSet($value))
		{
			return Loader::includeModule('crm') ? array_map('strval', DealCategory::getAllIDs()) : [];
		}

		return $this->toIdList($value);
	}

	private static function getStageDealCategoryId(string $stageId): string
	{
		return preg_match('/^C(\d+):/i', $stageId, $matches) ? $matches[1] : self::DEFAULT_DEAL_CATEGORY_ID;
	}

	/**
	 * The same test Helper::isFieldFilterable makes: the connector drops a field without it.
	 */
	private static function isFilterValueSet(mixed $value): bool
	{
		return $value || is_numeric($value);
	}

	/**
	 * Other values the connector turns into IDs of its own, e.g. [false] and [[5]] into 0 and 1.
	 *
	 * @return string[]|null IDs with '' for "without deal", null if any item is not a string or an integer.
	 */
	private function toIdList(mixed $value): ?array
	{
		if (!self::isFilterValueSet($value))
		{
			return [];
		}

		$ids = [];
		foreach (is_array($value) ? $value : [$value] as $item)
		{
			if ($item !== null && !is_string($item) && !is_int($item))
			{
				return null;
			}

			$ids[] = (string)$item;
		}

		return $ids;
	}
}
