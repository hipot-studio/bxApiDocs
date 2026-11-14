<?php
namespace Bitrix\Call\Integration\UI\EntitySelector;

use Bitrix\Call\Service\CallLogService;
use Bitrix\Call\Service\Exception\InvalidFilterValueException;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

/**
 * @internal
 */
class CallLogProvider extends BaseProvider
{
	protected const ENTITY_ID = 'call-log';

	/**
	 * Public, because the dialog builds the provider by reflection and cannot reach the constructor of the base.
	 */
	public function __construct(array $options = [])
	{
		parent::__construct();

		// Only the filters the dialog presets are taken from its options. The ids and the search term come with
		// the request, and the size of the answer is set here: an id list among the options would turn every
		// search into a lookup of those ids, and a limit would reach the query unchecked. The values are kept
		// as they came - the service refuses an unusable filter whole, while one dropped here would widen
		// the answer to the whole journal.
		foreach (['STATUS', 'TYPE'] as $filterKey)
		{
			if (isset($options[$filterKey]))
			{
				$this->options[$filterKey] = $options[$filterKey];
			}
		}
	}

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

		// One service for the whole request: the chats of a guest it reads to cut the rows are what the
		// enrichment of every row asks about next. A service of its own per row would find nothing read and go
		// to the database once per row.
		// The roster is left out: the dialog draws a title and a subtitle - see generateTitle() and
		// generateSubtitle() - and a search in it runs several times per interaction over as many as
		// MAX_LIMIT rows.
		$service = new CallLogService(withParticipants: false);
		$callLogs = static::getCallLogs($options, $service);

		return static::makeItems($callLogs, $options, $service);
	}

	public static function getCallLogs(array $options = [], ?CallLogService $service = null): array
	{
		$currentUserId = \Bitrix\Im\User::getInstance()->getId();
		$service ??= new CallLogService();

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
			// Passed on as it came, like the other two keys: dropping a falsy value here would hide an
			// unusable one from the service, and the empty term it stands for is what the service reads
			// as "not searching" anyway.
			if (isset($options['SEARCH']))
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
			try
			{
				$queryFilter = $service->buildQueryFilter($currentUserId, $filter);
			}
			catch (InvalidFilterValueException $e)
			{
				// The selector has no error channel of its own, and an exception let out of here would
				// break the whole selection dialog rather than this one section of it.
				return [];
			}

			if ($queryFilter === null)
			{
				return [];
			}
		}

		return $service->fetchAccessibleRows($currentUserId, $queryFilter, $options['limit'] ?? null);
	}

	public static function makeItems(array $callLogs, array $options = [], ?CallLogService $service = null): array
	{
		$service ??= new CallLogService(withParticipants: false);
		// Warmed on the very service makeItem() enriches the rows with: a warm-up on any other would leave the
		// mark of every row to a read of its own. The rows are raw, so the unseen marks of the batch are warmed
		// here as well, for the same owner makeItem() formats them for.
		$service->preloadRowData($callLogs, (int)\Bitrix\Im\User::getInstance()->getId());

		$result = [];
		foreach ($callLogs as $callLog)
		{
			$result[] = static::makeItem($callLog, $options, $service);
		}

		return $result;
	}

	public static function makeItem(array $callLog, array $options = [], ?CallLogService $service = null): Item
	{
		$userId = \Bitrix\Im\User::getInstance()->getId();

		// Format call log using service
		$service ??= new CallLogService(withParticipants: false);
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
