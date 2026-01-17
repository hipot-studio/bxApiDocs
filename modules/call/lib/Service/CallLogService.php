<?php

namespace Bitrix\Call\Service;

use Bitrix\Call\Logger\Logger;
use Bitrix\Main\Type\DateTime;
use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\Call\Model\CallUserLogCountersTable;
use Bitrix\Call\Service\CallLogPushService;
use Bitrix\Call\Counter;
use Bitrix\Im\Call\Call;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Application;

class CallLogService
{
	public const MIN_LIMIT = 1;
	public const MAX_LIMIT = 100;
	public const DEFAULT_LIMIT = 50;
	protected const LOCK_TTL = 10; // in seconds

	/**
	 * Validate and normalize limit value
	 *
	 * @param int|null $limit Requested limit
	 * @return int Normalized limit between MIN_LIMIT and MAX_LIMIT
	 */
	public static function normalizeLimit(?int $limit = null): int
	{
		if ($limit === null)
		{
			return self::DEFAULT_LIMIT;
		}
		return max(self::MIN_LIMIT, min($limit, self::MAX_LIMIT));
	}

	/**
	 * Get call events for user
	 *
	 * @param int $userId
	 * @param array $filter
	 * @param int $lastId
	 * @param int $limit
	 * @return array
	 */
	public function getList(int $userId, array $filter = [], int $lastId = 0, int $limit = self::DEFAULT_LIMIT): array
	{
		// Build query filter
		$queryFilter = $this->buildQueryFilter($userId, $filter, $lastId);

		// If search returned no results, return empty array
		if ($queryFilter === null)
		{
			return [];
		}

		$result = [];
		$dbResult = CallUserLogTable::getList([
			'select' => ['*'],
			'filter' => $queryFilter,
			'order' => ['STATUS_TIME' => 'DESC', 'ID' => 'DESC'],
			'limit' => self::normalizeLimit($limit)
		]);

		while ($row = $dbResult->fetch())
		{
			$result[] = $this->formatCallLog($row, $userId);
		}

		return $result;
	}

	/**
	 * Build query filter from user filter
	 *
	 * @param int $userId
	 * @param array $filter Filter parameters (STATUS, TYPE, SEARCH)
	 * @param int $lastId Last ID for pagination
	 * @return array|null Filter array or null if search returned no results
	 */
	public function buildQueryFilter(int $userId, array $filter = [], int $lastId = 0): ?array
	{
		$queryFilter = ['USER_ID' => $userId];

		// Pagination
		if ($lastId > 0)
		{
			$queryFilter['<ID'] = $lastId;
		}

		// Status filter
		if (!empty($filter['STATUS']))
		{
			$queryFilter['STATUS'] = $filter['STATUS'];
		}

		// Type filter (incoming/outgoing)
		if (!empty($filter['TYPE']) && empty($queryFilter['STATUS']))
		{
			$type = strtolower($filter['TYPE']);
			if ($type === 'incoming')
			{
				$queryFilter['STATUS'] = [
					CallUserLogTable::STATUS_MISSED,
					CallUserLogTable::STATUS_DECLINED,
					CallUserLogTable::STATUS_ANSWERED
				];
			}
			elseif ($type === 'outgoing')
			{
				$queryFilter['STATUS'] = CallUserLogTable::STATUS_INITIATED;
			}
		}

		// Search filter
		if (!empty($filter['SEARCH']))
		{
			$searchIds = $this->searchCallLogs(trim($filter['SEARCH']), $userId);
			if (!empty($searchIds))
			{
				$queryFilter['@ID'] = $searchIds;
			}
			else
			{
				// If nothing found, return null to indicate empty result
				return null;
			}
		}

		return $queryFilter;
	}

	/**
	 * Format call log row to API format
	 *
	 * @param array $row Raw database row
	 * @param int $userId User ID for checking unseen status
	 * @return array Formatted call log data
	 */
	public function formatCallLog(array $row, int $userId): array
	{
		$isUnseen = false;
		if ($row['STATUS'] === CallUserLogTable::STATUS_MISSED)
		{
			$isUnseen = $this->isUnseen((int)$row['ID'], $userId);
		}

		$type = $row['STATUS'] === CallUserLogTable::STATUS_INITIATED ? 'outgoing' : 'incoming';

		return [
			'id' => (int)$row['ID'],
			'sourceType' => $row['SOURCE_TYPE'],
			'sourceCallId' => (int)$row['SOURCE_CALL_ID'],
			'status' => $row['STATUS'],
			'statusTime' => $row['STATUS_TIME'] instanceof DateTime
				? $row['STATUS_TIME']->format(\DateTime::ATOM)
				: $row['STATUS_TIME'],
			'type' => $type,
			'isUnseen' => $isUnseen
		];
	}

	/**
	 * Check if call log entry is unseen
	 *
	 * @param int $callLogId
	 * @param int $userId
	 * @return bool
	 */
	private function isUnseen(int $callLogId, int $userId): bool
	{
		$counter = CallUserLogCountersTable::getList([
			'select' => ['ID'],
			'filter' => [
				'USERLOG_ID' => $callLogId,
				'USER_ID' => $userId
			],
			'limit' => 1
		])->fetch();

		return $counter !== false;
	}

	/**
	 * Get missed calls counter for user
	 *
	 * @param int $userId
	 * @return int
	 */
	public function getMissedCounter(int $userId): int
	{
		return CallUserLogCountersTable::getCount(['USER_ID' => $userId]);
	}

	/**
	 * Get call data based on source type
	 *
	 * @param array $call Call log entry (from b_call_userlog)
	 * @param int $userId Current user ID
	 * @return array
	 */
	public function getCallData(array $call, int $userId): array
	{
		$sourceType = $call['sourceType'] ?? $call['SOURCE_TYPE'] ?? '';
		if ($sourceType === CallUserLogTable::SOURCE_TYPE_VOXIMPLANT)
		{
			return $this->getVoximplantCallData($call, $userId);
		}
		elseif ($sourceType === CallUserLogTable::SOURCE_TYPE_CALL)
		{
			return $this->getImCallData($call, $userId);
		}
		return [];
	}

	/**
	 * Get voximplant call data
	 *
	 * @param array $call Call log entry from b_call_userlog
	 * @param int $currentUserId Current user ID (to determine the other participant)
	 * @return array
	 */
	private function getVoximplantCallData(array $call, int $currentUserId): array
	{
		if (!\Bitrix\Main\Loader::includeModule('voximplant'))
		{
			return [];
		}

		$sourceCallId = (int)($call['sourceCallId'] ?? $call['SOURCE_CALL_ID'] ?? 0);
		$status = $call['status'] ?? $call['STATUS'] ?? '';

		// Get statistic record by ID
		$statisticRecord = \Bitrix\Voximplant\StatisticTable::getById($sourceCallId)->fetch();
		if (!$statisticRecord)
		{
			return [];
		}

		// For missed and declined calls, duration should be 0
		$duration = (int)($statisticRecord['CALL_DURATION'] ?? 0);
		if (in_array($status, [CallUserLogTable::STATUS_MISSED, CallUserLogTable::STATUS_DECLINED]))
		{
			$duration = 0;
		}

		$result = [
			'phoneNumber' => $statisticRecord['PHONE_NUMBER'] ?: '',
			'duration' => $duration
		];

		$isInternalCall = $this->isInternalVoximplantCall($sourceCallId);
		if ($isInternalCall)
		{
			// Internal call - get other participant info
			$otherParticipant = $this->getOtherVoximplantParticipant($sourceCallId, $currentUserId);

			if ($otherParticipant)
			{
				$result['displayName'] = $otherParticipant['displayName'];
				$result['userId'] = $otherParticipant['userId'];

				// For receiver, show initiator's internal number
				if (isset($otherParticipant['phoneNumber']))
				{
					$result['phoneNumber'] = $otherParticipant['phoneNumber'];
				}
			}
		}
		else
		{
			// External call - try to get contact name from CRM if available
			if ($statisticRecord['CRM_ENTITY_TYPE'] && $statisticRecord['CRM_ENTITY_ID'])
			{
				$displayName = $this->getCrmEntityName(
					$statisticRecord['CRM_ENTITY_TYPE'],
					$statisticRecord['CRM_ENTITY_ID']
				);
				if ($displayName)
				{
					$result['displayName'] = $displayName;
				}
			}

			// Fallback to phone number as display name
			if (empty($result['displayName']))
			{
				$result['displayName'] = $result['phoneNumber'] ?: 'Unknown';
			}
		}

		return $result;
	}

	/**
	 * Check if voximplant call is internal (between two users)
	 *
	 * @param int $sourceCallId Voximplant statistic ID
	 * @return bool
	 */
	private function isInternalVoximplantCall(int $sourceCallId): bool
	{
		$count = CallUserLogTable::getCount([
			'=SOURCE_TYPE' => CallUserLogTable::SOURCE_TYPE_VOXIMPLANT,
			'=SOURCE_CALL_ID' => $sourceCallId
		]);

		return $count > 1;
	}

	/**
	 * Get other participant info for internal voximplant call
	 *
	 * @param int $sourceCallId Voximplant statistic ID
	 * @param int $currentUserId Current user ID
	 * @return array|null
	 */
	private function getOtherVoximplantParticipant(int $sourceCallId, int $currentUserId): ?array
	{
		$logEntries = CallUserLogTable::getList([
			'filter' => [
				'=SOURCE_TYPE' => CallUserLogTable::SOURCE_TYPE_VOXIMPLANT,
				'=SOURCE_CALL_ID' => $sourceCallId
			],
			'select' => ['USER_ID', 'STATUS'],
			'order' => ['ID' => 'ASC']
		])->fetchAll();

		$otherUserId = null;
		$initiatorId = null;

		foreach ($logEntries as $entry)
		{
			if ($entry['STATUS'] === CallUserLogTable::STATUS_INITIATED)
			{
				$initiatorId = (int)$entry['USER_ID'];
			}
			if ((int)$entry['USER_ID'] !== $currentUserId)
			{
				$otherUserId = (int)$entry['USER_ID'];
			}
		}

		if (!$otherUserId)
		{
			return null;
		}

		$user = \CUser::GetByID($otherUserId)->Fetch();
		if (!$user)
		{
			return null;
		}

		$result = [
			'displayName' => \CUser::FormatName(
				\CSite::GetNameFormat(false),
				$user,
				true,
				false
			),
			'userId' => $otherUserId
		];

		// For the receiver, show initiator's internal Voximplant number
		if ($initiatorId && $initiatorId !== $currentUserId)
		{
			$phoneRecord = \Bitrix\Voximplant\PhoneTable::getList([
				'filter' => ['=USER_ID' => $initiatorId],
				'select' => ['PHONE_NUMBER'],
				'limit' => 1
			])->fetch();

			if ($phoneRecord && $phoneRecord['PHONE_NUMBER'])
			{
				$result['phoneNumber'] = $phoneRecord['PHONE_NUMBER'];
			}
		}

		return $result;
	}

	/**
	 * Get IM call data
	 *
	 * @param array $call Call log entry from b_call_userlog
	 * @param int $currentUserId Current user ID
	 * @return array
	 */
	private function getImCallData(array $call, int $currentUserId): array
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return [];
		}

		$sourceCallId = (int)($call['sourceCallId'] ?? $call['SOURCE_CALL_ID'] ?? 0);

		// Load call object using standard method
		$callObject = Call::loadWithId($sourceCallId);
		if (!$callObject)
		{
			return [];
		}

		// Get associated entity (Chat entity)
		$chatEntity = $callObject->getAssociatedEntity();
		if (!$chatEntity)
		{
			return [];
		}

		$result = [
			'chatId' => $callObject->getChatId(),
			'title' => $chatEntity->getName($currentUserId) ?: 'Untitled Chat',
			'avatar' => $chatEntity->getAvatar($currentUserId) ?: '',
			'color' => $chatEntity->getAvatarColor($currentUserId) ?: ''
		];

		// Calculate call duration
		$result['duration'] = $this->getCallDuration($callObject);

		// Count users in call
		$result['userCount'] = $callObject->getUserCount();

		// Determine chat type and dialogId
		if ($chatEntity->isPrivateChat())
		{
			$result['dialogId'] = (string)$chatEntity->getEntityId($currentUserId);
			$result['chatType'] = 'private';
		}
		else
		{
			$result['dialogId'] = 'chat' . $callObject->getChatId();
			$result['chatType'] = 'group';
		}

		return $result;
	}

	/**
	 * Calculate call duration based on participants who actually joined
	 * Returns duration in seconds if 2+ users joined (FIRST_JOINED is set), otherwise 0
	 *
	 * @param Call $call Call object
	 * @return int Duration in seconds
	 */
	private function getCallDuration(Call $call): int
	{
		$startDate = $call->getStartDate();
		$endDate = $call->getEndDate();

		if (!$startDate || !$endDate)
		{
			return 0;
		}

		// Get all call participants
		$callUsers = $call->getCallUsers();

		// Count how many users actually joined the call
		$joinedCount = 0;
		foreach ($callUsers as $callUser)
		{
			if ($callUser->getFirstJoined() !== null)
			{
				$joinedCount++;
			}
		}

		// If 2 or more users joined - calculate duration
		if ($joinedCount >= 2)
		{
			$duration = $endDate->getTimestamp() - $startDate->getTimestamp();
			return max(0, $duration);
		}

		return 0;
	}

	/**
	 * Mark calls as seen
	 *
	 * @param int $userId
	 * @param array $callIds
	 * @return int New counter value
	 */
	public function markAsSeen(int $userId, array $callIds): int
	{
		if (empty($callIds))
		{
			return $this->getMissedCounter($userId);
		}

		// Delete all counters for specified call IDs
		CallUserLogCountersTable::deleteByFilter([
			'USER_ID' => $userId,
			'USERLOG_ID' => $callIds
		]);

		Counter::clearCache($userId);

		$newCounterValue = $this->getMissedCounter($userId);

		CallLogPushService::sendCounterUpdate($userId, $newCounterValue);

		return $newCounterValue;
	}

	/**
	 * Mark all calls as seen for user
	 *
	 * @param int $userId
	 * @return int New counter value (should be 0)
	 */
	public function markAllAsSeen(int $userId): int
	{
		// Delete all counters for user
		CallUserLogCountersTable::deleteByFilter(['USER_ID' => $userId]);

		Counter::clearCache($userId);

		CallLogPushService::sendCounterUpdate($userId, 0);

		return 0;
	}

	/**
	 * Delete single call log entry for user
	 *
	 * @param int $userId
	 * @param int $callId Call log ID to delete
	 * @return bool True if deleted, false if not found or not owned by user
	 */
	public function deleteEntry(int $userId, int $callId): bool
	{
		$oldCounter = $this->getMissedCounter($userId);

		CallUserLogCountersTable::deleteByFilter([
			'USER_ID' => $userId,
			'USERLOG_ID' => $callId
		]);

		Counter::clearCache($userId);

		try
		{
			CallUserLogTable::deleteByFilter([
				'ID' => $callId,
				'USER_ID' => $userId
			]);

			$recordExists = CallUserLogTable::getCount([ 'ID' => $callId ]) > 0;
			if ($recordExists)
			{
				return false;
			}

			\Bitrix\Call\Model\CallUserLogIndexTable::deleteByFilter([
				'USERLOG_ID' => $callId
			]);

			$newCounter = $this->getMissedCounter($userId);
			if ($newCounter !== $oldCounter)
			{
				CallLogPushService::sendCounterUpdate($userId, $newCounter);
			}

			return true;
		}
		catch (\Exception $e)
		{
			return false;
		}
	}

	protected function getLockName(string $sourceType, int $sourceCallId, int $userId): string
	{
		return "userlog_{$sourceType}_{$sourceCallId}_{$userId}";
	}

	/**
	 * Add new event or update existing one using merge strategy
	 *
	 * @param string $sourceType
	 * @param int $sourceCallId
	 * @param int $userId
	 * @param string $status
	 * @param DateTime|null $statusTime
	 * @return int|null
	 */
	public function addOrUpdateEvent(string $sourceType, int $sourceCallId, int $userId, string $status, ?DateTime $statusTime = null): ?int
	{
		$logger = Logger::getInstance();

		if (!$statusTime)
		{
			$statusTime = new DateTime();
		}

		$logger->info("Received new userlog event for {$sourceType}/{$sourceCallId}/{$userId}/{$status} at {$statusTime}");

		$lockName = $this->getLockName($sourceType, $sourceCallId, $userId);
		$connection = Application::getConnection();

		if (!$connection->lock($lockName, self::LOCK_TTL))
		{
			$logger->error("Failed to lock {$lockName}");
			return null;
		}
		$logger->notice("Locked userlog event for {$lockName}");

		try
		{
			$existingRecord = CallUserLogTable::getList([
				'filter' => [
					'SOURCE_TYPE' => $sourceType,
					'SOURCE_CALL_ID' => $sourceCallId,
					'USER_ID' => $userId
				],
				'select' => ['ID', 'STATUS'],
				'limit' => 1
			])->fetch();

			$isUpdate = (bool)$existingRecord;
			$oldStatus = $existingRecord ? $existingRecord['STATUS'] : null;

			$result = CallUserLogTable::addMerge([
				'SOURCE_TYPE' => $sourceType,
				'SOURCE_CALL_ID' => $sourceCallId,
				'USER_ID' => $userId,
				'STATUS' => $status,
				'STATUS_TIME' => $statusTime
			]);

			if (!$result->isSuccess())
			{
				$logger->error("Failed to merge data for {$lockName}");
				return null;
			}

			$eventId = $result->getId();

			if ($isUpdate)
			{
				$this->updateCallLogIndex($eventId, $sourceType, $sourceCallId, $userId);
			}
			else
			{
				$this->addCallLogIndex($eventId, $sourceType, $sourceCallId, $userId);
			}

			$callLogData = [
				'id' => $eventId,
				'sourceType' => $sourceType,
				'sourceCallId' => $sourceCallId,
				'userId' => $userId,
				'status' => $status,
				'statusTime' => $statusTime->format(\DateTime::ATOM),
			];

			if ($oldStatus)
			{
				$callLogData['oldStatus'] = $oldStatus;
			}

			$callLogData['callData'] = $this->getCallData($callLogData, $userId);

			if ($status === CallUserLogTable::STATUS_MISSED && $oldStatus !== CallUserLogTable::STATUS_MISSED)
			{
				$this->addToCounter($eventId, $userId);
			}

			$command = $isUpdate ? 'Update' : 'Add';
			CallLogPushService::sendCallLog($command, $callLogData);

			return $eventId;
		}
		finally
		{
			$logger->notice("Unlock {$lockName}");
			$connection->unlock($lockName);
		}
	}

	/**
	 * Add event to missed counter
	 *
	 * @param int $callLogId
	 * @param int $userId
	 * @return void
	 */
	private function addToCounter(int $callLogId, int $userId): void
	{
		try
		{
			CallUserLogCountersTable::add([
				'USERLOG_ID' => $callLogId,
				'USER_ID' => $userId
			]);
		}
		catch (DuplicateEntryException $e)
		{
			// Record already exists, ignore duplicate entry error
			return;
		}

		Counter::clearCache($userId);

		$newCounterValue = $this->getMissedCounter($userId);
		CallLogPushService::sendCounterUpdate($userId, $newCounterValue);
	}


	/**
	 * Get CRM entity display name
	 */
	private function getCrmEntityName(string $entityType, int $entityId): ?string
	{
		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return null;
		}

		$entityTypeNormalized = strtoupper($entityType);
		$entityTypeId = \CCrmOwnerType::ResolveID($entityTypeNormalized);

		if ($entityTypeId === \CCrmOwnerType::Undefined)
		{
			return null;
		}

		// Use CRM Service Container to get entity factory
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return null;
		}

		$entity = $factory->getItem($entityId);
		if (!$entity)
		{
			return null;
		}

		// Get formatted title using CRM API
		return $entity->getHeading();
	}

	/**
	 * Search in call logs by chat name, user name, or phone number using FTI
	 *
	 * @param string $search Search query
	 * @param int $userId Current user ID
	 * @return array Array of found call log IDs
	 */
	private function searchCallLogs(string $search, int $userId): array
	{
		if (mb_strlen($search) < \Bitrix\Main\ORM\Query\Filter\Helper::getMinTokenSize())
		{
			return [];
		}

		$query = \Bitrix\Call\Model\CallUserLogTable::query();
		$query
			->addSelect('ID')
			->where('USER_ID', $userId);

		$query->registerRuntimeField(
			'CALL_LOG_INDEX',
			new Reference(
				'CALL_LOG_INDEX',
				\Bitrix\Call\Model\CallUserLogIndexTable::class,
				Join::on('this.ID', 'ref.USERLOG_ID'),
				['join_type' => Join::TYPE_INNER]
			)
		);

		$searchText = \Bitrix\Main\ORM\Query\Filter\Helper::matchAgainstWildcard(
			\Bitrix\Main\Search\Content::prepareStringToken($search)
		);
		$query->whereMatch('CALL_LOG_INDEX.SEARCH_CONTENT', $searchText);

		$ids = [];
		foreach ($query->exec() as $row)
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	private function addCallLogIndex(int $callLogId, string $sourceType, int $sourceCallId, int $userId): void
	{
		$indexData = $this->prepareIndexData($sourceType, $sourceCallId, $userId);
		if ($indexData === null)
		{
			return;
		}

		$index = \Bitrix\Call\Internals\CallLogIndex::create()
			->setCallLogId($callLogId)
			->setTitle($indexData['title'])
			->setUserNames($indexData['userNames'])
			->setPhoneNumbers($indexData['phoneNumbers']);

		CallUserLogTable::addIndexRecord($index);
	}

	private function updateCallLogIndex(int $callLogId, string $sourceType, int $sourceCallId, int $userId): void
	{
		$indexData = $this->prepareIndexData($sourceType, $sourceCallId, $userId);
		if ($indexData === null)
		{
			return;
		}

		$index = \Bitrix\Call\Internals\CallLogIndex::create()
			->setCallLogId($callLogId)
			->setTitle($indexData['title'])
			->setUserNames($indexData['userNames'])
			->setPhoneNumbers($indexData['phoneNumbers']);

		CallUserLogTable::updateIndexRecord($index);
	}

	private function prepareIndexData(string $sourceType, int $sourceCallId, int $userId): ?array
	{
		$title = '';
		$userNames = [];
		$phoneNumbers = [];

		if ($sourceType === CallUserLogTable::SOURCE_TYPE_VOXIMPLANT)
		{
			if (!\Bitrix\Main\Loader::includeModule('voximplant'))
			{
				return null;
			}

			$statisticRecord = \Bitrix\Voximplant\StatisticTable::getById($sourceCallId)->fetch();
			if (!$statisticRecord)
			{
				return null;
			}

			if (!empty($statisticRecord['PHONE_NUMBER']))
			{
				$phoneNumbers[] = $statisticRecord['PHONE_NUMBER'];
			}

			$isInternalCall = $this->isInternalVoximplantCall($sourceCallId);
			if ($isInternalCall)
			{
				$otherParticipant = $this->getOtherVoximplantParticipant($sourceCallId, $userId);
				if ($otherParticipant && !empty($otherParticipant['displayName']))
				{
					$userNames[] = $otherParticipant['displayName'];
					$title = $otherParticipant['displayName'];
				}
			}
			else
			{
				if ($statisticRecord['CRM_ENTITY_TYPE'] && $statisticRecord['CRM_ENTITY_ID'])
				{
					$displayName = $this->getCrmEntityName(
						$statisticRecord['CRM_ENTITY_TYPE'],
						$statisticRecord['CRM_ENTITY_ID']
					);
					if ($displayName)
					{
						$title = $displayName;
						$userNames[] = $displayName;
					}
				}
			}
		}
		elseif ($sourceType === CallUserLogTable::SOURCE_TYPE_CALL)
		{
			if (!\Bitrix\Main\Loader::includeModule('im'))
			{
				return null;
			}

			$callObject = Call::loadWithId($sourceCallId);
			if (!$callObject)
			{
				return null;
			}

			$chatEntity = $callObject->getAssociatedEntity();
			if (!$chatEntity)
			{
				return null;
			}

			$chatName = $chatEntity->getName($userId);
			if ($chatName)
			{
				$title = $chatName;
			}

			if ($chatEntity->isPrivateChat())
			{
				$otherUserId = (int)$chatEntity->getEntityId($userId);
				$user = \CUser::GetByID($otherUserId)->Fetch();
				if ($user)
				{
					$userName = \CUser::FormatName(
						\CSite::GetNameFormat(false),
						$user,
						true,
						false
					);
					if ($userName)
					{
						$userNames[] = $userName;
						if (empty($title))
						{
							$title = $userName;
						}
					}
				}
			}
		}

		return [
			'title' => $title,
			'userNames' => $userNames,
			'phoneNumbers' => $phoneNumbers,
		];
	}

}
