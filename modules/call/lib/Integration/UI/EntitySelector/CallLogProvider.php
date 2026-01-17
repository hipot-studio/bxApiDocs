<?php
namespace Bitrix\Call\Integration\UI\EntitySelector;

use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

class CallLogProvider extends BaseProvider
{
	protected const ENTITY_ID = 'call-log';

	protected static function getEntityId(): string
	{
		return static::ENTITY_ID;
	}

	public function isAvailable(): bool
	{
		$currentUser = \Bitrix\Main\Engine\CurrentUser::get();
		return $currentUser !== null && $currentUser->getId() > 0;
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$items = $this->getCallLogItems([
			'SEARCH' => $searchQuery->getQuery(),
			'limit' => \Bitrix\Call\Service\CallLogService::MAX_LIMIT
		]);

		$dialog->addItems($items);
	}

	public function getItems(array $ids): array
	{
		return $this->getCallLogItems([
			'callLogIds' => $ids,
		]);
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	public function getCallLogItems(array $options = []): array
	{
		$options = array_merge($this->getOptions(), $options);
		$callLogs = static::getCallLogs($options);
		return static::makeItems($callLogs, $options);
	}

	public static function getCallLogs(array $options = []): array
	{
		$currentUserId = \Bitrix\Im\User::getInstance()->getId();
		$service = new \Bitrix\Call\Service\CallLogService();

		// Build filter
		$queryFilter = ['USER_ID' => $currentUserId];

		// Handle specific call log IDs
		if (isset($options['callLogIds']) && is_array($options['callLogIds']))
		{
			$queryFilter['@ID'] = $options['callLogIds'];
		}
		else
		{
			// Build filter using service (use same keys as REST API)
			$filter = [];
			if (isset($options['SEARCH']) && $options['SEARCH'])
			{
				$filter['SEARCH'] = $options['SEARCH'];
			}
			if (isset($options['STATUS']))
			{
				$filter['STATUS'] = $options['STATUS'];
			}
			if (isset($options['TYPE']))
			{
				$filter['TYPE'] = $options['TYPE'];
			}

			// Build query filter using service
			$queryFilter = $service->buildQueryFilter($currentUserId, $filter);

			if ($queryFilter === null)
			{
				return [];
			}
		}

		// Use getList instead of query builder for consistency with service
		$result = CallUserLogTable::getList([
			'select' => ['*'],
			'filter' => $queryFilter,
			'order' => ['STATUS_TIME' => 'DESC', 'ID' => 'DESC'],
			'limit' => \Bitrix\Call\Service\CallLogService::normalizeLimit($options['limit'] ?? null)
		]);

		return $result->fetchAll();
	}

	public static function makeItems(array $callLogs, array $options = []): array
	{
		$result = [];
		foreach ($callLogs as $callLog)
		{
			$result[] = static::makeItem($callLog, $options);
		}

		return $result;
	}

	public static function makeItem(array $callLog, array $options = []): Item
	{
		$userId = \Bitrix\Im\User::getInstance()->getId();

		// Format call log using service
		$service = new \Bitrix\Call\Service\CallLogService();
		$formattedCallLog = $service->formatCallLog($callLog, $userId);
		$formattedCallLog['callData'] = $service->getCallData($formattedCallLog, $userId);

		// Generate UI title and subtitle
		$title = static::generateTitle($formattedCallLog);
		$subtitle = static::generateSubtitle($formattedCallLog);

		return new Item([
			'id' => $formattedCallLog['id'],
			'entityId' => static::getEntityId(),
			'title' => $title,
			'subtitle' => $subtitle,
			'customData' => [
				'callLog' => $formattedCallLog,
			],
		]);
	}

	/**
	 * Generate title for UI display
	 *
	 * @param array $callLog Formatted call log data
	 * @return string
	 */
	protected static function generateTitle(array $callLog): string
	{
		$callData = $callLog['callData'] ?? [];

		// For IM calls
		if (isset($callData['title']) && $callData['title'])
		{
			return $callData['title'];
		}

		// For Voximplant calls
		if (isset($callData['displayName']) && $callData['displayName'])
		{
			return $callData['displayName'];
		}

		// Fallback to phone number
		if (isset($callData['phoneNumber']) && $callData['phoneNumber'])
		{
			return $callData['phoneNumber'];
		}

		return 'Call #' . $callLog['id'];
	}

	/**
	 * Generate subtitle for UI display
	 *
	 * @param array $callLog Formatted call log data
	 * @return string
	 */
	protected static function generateSubtitle(array $callLog): string
	{
		$parts = [];

		// Type and status
		$type = $callLog['type'] === 'outgoing' ? 'Исходящий' : 'Входящий';
		$parts[] = $type;

		// Duration if available
		$callData = $callLog['callData'] ?? [];
		if (isset($callData['duration']) && $callData['duration'] > 0)
		{
			$duration = $callData['duration'];
			$minutes = floor($duration / 60);
			$seconds = $duration % 60;
			$parts[] = sprintf('%d:%02d', $minutes, $seconds);
		}

		return implode(' • ', $parts);
	}
}
