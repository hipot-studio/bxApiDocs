<?php

namespace Bitrix\Im\V2\Sync;

use Bitrix\Im\Model\EO_Log_Collection;
use Bitrix\Im\Model\LogTable;
use Bitrix\Im\Model\UserTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Pull\Model\PushTable;

class Logger
{
	use ContextCustomer;

	public const DEFAULT_EXPIRY_INTERVAL = '+4 weeks';
	public const FAST_EXPIRY_INTERVAL = '+1 days';
	protected const CHAT_TYPE_BLACKLIST = [
		Chat::IM_TYPE_OPEN_LINE,
		Chat::IM_TYPE_COMMENT,
	];

	private static Logger $instance;

	private bool $isAlreadyPlanned = false;
	private array $events = [];
	private ?array $allowedUsers = null;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		self::$instance ??= new Logger();

		return self::$instance;
	}

	public function add(Event $event, mixed $userId, ?Chat $chat = null): void
	{
		if (!SyncService::isEnable())
		{
			return;
		}

		if (!$this->needToLog($chat))
		{
			return;
		}

		$userId ??= $this->getContext()->getUserId();
		$this->events[] = ['event' => $event, 'user' => $userId];

		if (!$this->isAlreadyPlanned)
		{
			Application::getInstance()->addBackgroundJob(fn () => $this->addDeferred(), [], Application::JOB_PRIORITY_LOW);
			$this->isAlreadyPlanned = true;
		}
	}

	/**
	 * Writes a global lifecycle event with USER_ID = 0, bypassing the active-user filter.
	 * Used for user lifecycle events (fired, restored, deleted) visible to all mobile clients.
	 *
	 * @param Event $event    The event to record.
	 * @param bool  $deferred When true (default), write is scheduled as a background job (safe for
	 *                        kernel hook handlers). When false, write is immediate (for agent/cron callers).
	 */
	public function addGlobal(Event $event, bool $deferred = true): void
	{
		if (!SyncService::isEnable())
		{
			return;
		}

		if ($deferred)
		{
			Application::getInstance()->addBackgroundJob(
				fn () => $this->addGlobalDeferred($event),
				[],
				Application::JOB_PRIORITY_LOW
			);
		}
		else
		{
			$this->writeGlobalEvent($event);
		}
	}

	public function updateDateDelete(EO_Log_Collection $logs, ?DateTime $dateDelete = null): void
	{
		Application::getInstance()->addBackgroundJob(fn () => $this->updateDateDeleteDeferred($logs, $dateDelete));
	}

	public static function cleanAgent(): string
	{
		return '';

		(new static())->clean();

		return __METHOD__ . '();';
	}

	public function clean(): void
	{
		$now = new DateTime();
		LogTable::deleteByFilter(['<=DATE_DELETE' => $now]); //Todo: add index in b_im_log
	}

	protected function needToLog(?Chat $chat): bool
	{
		if ($chat === null)
		{
			return true;
		}

		if (
			$chat instanceof Chat\NullChat
			|| $chat instanceof Chat\NotifyChat
		)
		{
			return false;
		}

		if (in_array($chat->getType(), self::CHAT_TYPE_BLACKLIST, true))
		{
			return false;
		}

		return !empty($chat->getRecentSections());
	}

	private function addDeferred(): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$this->runClosureInEvents();
		$groupedEvents = $this->getGroupedEvents();

		foreach ($groupedEvents as ['event' => $event, 'user' => $userId])
		{
			LogTable::multiplyMerge(...$this->getMultiplyMergeParam($event, $userId));
		}
		$this->events = [];
		$this->isAlreadyPlanned = false;
	}

	private function addGlobalDeferred(Event $event): void
	{
		$this->writeGlobalEvent($event);
	}

	private function writeGlobalEvent(Event $event): void
	{
		LogTable::multiplyMerge(
			...$this->getMultiplyMergeParam($event, [0 => 0])
		);
	}

	/**
	 * Writes a batch of global lifecycle events (USER_ID = 0) in a SINGLE multiplyMerge upsert.
	 *
	 * Each event becomes one (0, ENTITY_TYPE, ENTITY_ID) row; the DB matches and updates each row
	 * independently by the unique key, so one shared update clause is correct. Used by agents that
	 * backfill many events of the same kind (e.g. userFired) without N separate SQL round-trips.
	 *
	 * @param Event[] $events Events to record. No-op when empty.
	 */
	public function writeGlobalEvents(array $events): void
	{
		if (empty($events))
		{
			return;
		}

		if (!SyncService::isEnable())
		{
			return;
		}

		$insertFields = [];
		foreach ($events as $event)
		{
			$insertFields[] = [
				'USER_ID' => 0,
				'ENTITY_TYPE' => $event->entityType,
				'ENTITY_ID' => $event->entityId,
				'EVENT' => $event->eventName,
				'DATE_CREATE' => $event->getDateCreate(),
				'DATE_DELETE' => $event->getDateDelete(),
			];
		}

		$lastEvent = $events[array_key_last($events)];

		LogTable::multiplyMerge(
			$insertFields,
			[
				'EVENT' => $lastEvent->eventName,
				'DATE_CREATE' => $lastEvent->getDateCreate(),
				'DATE_DELETE' => $lastEvent->getDateDelete(),
			],
			[
				'USER_ID',
				'ENTITY_TYPE',
				'ENTITY_ID',
			],
			['DEADLOCK_SAFE' => true]
		);
	}

	private function updateDateDeleteDeferred(EO_Log_Collection $logs, ?DateTime $dateDelete): void
	{
		return;

		if ($dateDelete === null)
		{
			$dateDelete = new DateTime();
			$dateDelete->add(self::FAST_EXPIRY_INTERVAL);
		}

		$newDateDeleteTs = $dateDelete->getTimestamp();
		foreach ($logs as $log)
		{
			$oldDateDelete = $log->getDateDelete();
			if ($oldDateDelete === null || $oldDateDelete->getTimestamp() > $newDateDeleteTs)
			{
				$log->setDateDelete($dateDelete);
			}
		}

		$logs->save(true);
	}

	private function getGroupedEvents(): array
	{
		$result = [];

		/** @var Event $event */
		foreach ($this->events as ['event' => $event, 'user' => $userId])
		{
			$userId = $this->filterUsers($this->getUsersFromEvent(['user' => $userId]));

			if (empty($userId))
			{
				continue;
			}

			$key = "{$event->eventName}|{$event->entityType}|{$event->entityId}";

			if (isset($result[$key]['user']))
			{
				$result[$key]['user'] = $this->mergeByKey($result[$key]['user'], $userId);
			}
			else
			{
				$result[$key] = ['event' => $event, 'user' => $userId];
			}
		}

		return array_values($result);
	}

	private function filterUsers(array $users): array
	{
		if (!isset($this->allowedUsers))
		{
			$this->fillAllowedUsers();
		}

		foreach ($users as $key => $userId)
		{
			if (!isset($this->allowedUsers[$userId]))
			{
				unset($users[$key]);
			}
		}

		return $users;
	}

	private function fillAllowedUsers(): void
	{
		$allUsers = $this->getUsers();

		foreach ($allUsers as $userId)
		{
			$user = User::getInstance($userId);
			$isRealUser = !in_array($user->getExternalAuthId(), UserTable::filterExternalUserTypes([UserGuest::AUTH_ID]), true);
			if ($isRealUser && $user->isActive())
			{
				$this->allowedUsers[$userId] = $userId;
			}
		}
	}

	private function getUsers(): array
	{
		$users = [];

		foreach ($this->events as $event)
		{
			$eventUsers = $this->getUsersFromEvent($event);

			foreach ($eventUsers as $eventUser)
			{
				$users[$eventUser] = $eventUser;
			}
		}

		return $users;
	}

	private function getUsersFromEvent(array $eventItem): array
	{
		$users = $eventItem['user'] ?? [];

		if (is_int($users))
		{
			return [$users => $users];
		}

		if (is_array($users))
		{
			$result = [];

			foreach ($users as $id)
			{
				$result[$id] = $id;
			}

			return $result;
		}

		return [];
	}

	private function filterWithoutMobile(array $userIds): array
	{
		if (empty($userIds))
		{
			return $userIds;
		}

		return PushTable::query()
			->setSelect(['USER_ID'])
			->whereIn('USER_ID', $userIds)
			->fetchCollection()
			->getUserIdList()
		;
	}

	private function filterInactive(array $userIds): array
	{
		if (empty($userIds))
		{
			return $userIds;
		}

		return UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where('ACTIVE', true)
			->where('REAL_USER', 'expr', true)
			->fetchCollection()
			->getIdList()
		;
	}

	private function runClosureInEvents(): void
	{
		foreach ($this->events as $key => ['event' => $event, 'user' => $userId])
		{
			if (is_callable($userId))
			{
				$this->events[$key]['user'] = $userId();
			}
		}
	}

	private function mergeByKey(array ...$arrays): array
	{
		$result = [];
		foreach ($arrays as $array)
		{
			foreach ($array as $key => $value)
			{
				$result[$key] = $value;
			}
		}

		return $result;
	}

	private function getMergeParam(Event $event, array $userId): array
	{
		$intUserId = array_values($userId)[0];

		return [
			[
				'USER_ID' => $intUserId,
				'ENTITY_TYPE' => $event->entityType,
				'ENTITY_ID' => $event->entityId,
				'EVENT' => $event->eventName,
				'DATE_CREATE' => $event->getDateCreate(),
				'DATE_DELETE' => $event->getDateDelete(),
			],
			[
				'EVENT' => $event->eventName,
				'DATE_CREATE' => $event->getDateCreate(),
				'DATE_DELETE' => $event->getDateDelete(),
			],
			[
				'USER_ID',
				'ENTITY_TYPE',
				'ENTITY_ID',
			]
		];
	}

	private function getMultiplyMergeParam(Event $event, array $userId): array
	{
		$insertFields = [];

		foreach ($userId as $id)
		{
			$insertFields[] = [
				'USER_ID' => $id,
				'ENTITY_TYPE' => $event->entityType,
				'ENTITY_ID' => $event->entityId,
				'EVENT' => $event->eventName,
				'DATE_CREATE' => $event->getDateCreate(),
				'DATE_DELETE' => $event->getDateDelete(),
			];
		}

		return [
			$insertFields,
			[
				'EVENT' => $event->eventName,
				'DATE_CREATE' => $event->getDateCreate(),
				'DATE_DELETE' => $event->getDateDelete(),
			],
			[
				'USER_ID',
				'ENTITY_TYPE',
				'ENTITY_ID',
			],
			['DEADLOCK_SAFE' => true]
		];
	}
}
