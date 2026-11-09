<?php

namespace Bitrix\Rpa\Integration;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Pull\Event;
use Bitrix\Pull\Model\WatchTable;
use Bitrix\Rpa\Driver;
use Bitrix\Rpa\Model\Item;
use Bitrix\Rpa\Model\Stage;
use Bitrix\Rpa\Model\Timeline;
use Bitrix\Rpa\Model\Type;
use Bitrix\Rpa\UserPermissions;

class PullManager
{
	protected const EVENT_KANBAN_UPDATED = 'KANBANUPDATED';
	protected const EVENT_TYPE_UPDATED = 'TYPEUPDATED';
	protected const EVENT_STAGE_UPDATED = 'STAGEUPDATED';
	protected const EVENT_ITEM_UPDATED = 'ITEMUPDATED';
	protected const EVENT_STAGE_DELETED = 'STAGEDELETED';
	protected const EVENT_ITEM_DELETED = 'ITEMDELETED';
	protected const EVENT_STAGE_ADDED = 'STAGEADDED';
	protected const EVENT_ITEM_ADDED = 'ITEMADDED';
	protected const EVENT_ROBOT_ADDED = 'ROBOTADDED';
	protected const EVENT_ROBOT_UPDATED = 'ROBOTUPDATED';
	protected const EVENT_ROBOT_DELETED = 'ROBOTDELETED';
	protected const EVENT_TIMELINE = 'TIMELINEUPDATED';
	protected const EVENT_TASK_COUNTERS = 'TASKCOUNTERS';

	protected $eventIds = [];
	protected $itemViewerIds = [];
	protected $isEnabled;

	public function __construct()
	{
		$this->isEnabled = $this->includeModule();
	}

	protected function includeModule(): bool
	{
		try
		{
			return Loader::includeModule('pull');
		}
		catch(LoaderException $exception)
		{
			return false;
		}
	}

	public function isEnabled(): bool
	{
		return $this->isEnabled;
	}

	//region subscribe
	public function subscribeOnTimelineUpdate(int $typeId, int $itemId): ?string
	{
		return $this->subscribeOnEvent(static::getEventName(static::EVENT_TIMELINE, $typeId, $itemId));
	}

	public function subscribeOnKanbanUpdate(int $typeId): ?string
	{
		return $this->subscribeOnEvent($this->getKanbanTag($typeId));
	}

	public function subscribeOnItemUpdatedEvent(int $typeId, int $itemId): ?string
	{
		return $this->subscribeOnEvent(static::getEventName(static::EVENT_ITEM_UPDATED, $typeId, $itemId));
	}

	public function subscribeOnTaskCounters(): ?string
	{
		return $this->subscribeOnEvent(static::EVENT_TASK_COUNTERS);
	}
	//endregion

	//region send events
	public function sendTimelineAddEvent(Timeline $timeline, string $eventId = ''): bool
	{
		$typeId = $timeline->getTypeId();
		$itemId = $timeline->getItemId();
		if($typeId > 0 && $itemId > 0)
		{
			$eventName = static::getEventName(static::EVENT_TIMELINE, $typeId, $itemId);
			$eventParams = $this->prepareTimelineEventParams($timeline, 'add', $eventId);

			return $this->sendItemTimelineEvent($timeline->getItem(), $eventName, $eventParams);
		}

		return false;
	}

	public function sendTimelineUpdateEvent(Timeline $timeline, string $eventId = ''): bool
	{
		$typeId = $timeline->getTypeId();
		$itemId = $timeline->getItemId();
		if($typeId > 0 && $itemId > 0)
		{
			$eventName = static::getEventName(static::EVENT_TIMELINE, $typeId, $itemId);
			$eventParams = $this->prepareTimelineEventParams($timeline, 'update', $eventId);

			return $this->sendItemTimelineEvent($timeline->getItem(), $eventName, $eventParams);
		}

		return false;
	}

	public function sendTimelinePinEvent(Timeline $timeline, string $eventId = ''): bool
	{
		$typeId = $timeline->getTypeId();
		$itemId = $timeline->getItemId();
		if($typeId > 0 && $itemId > 0)
		{
			$eventName = static::getEventName(static::EVENT_TIMELINE, $typeId, $itemId);
			$eventParams = $this->prepareTimelineEventParams($timeline, 'pin', $eventId);

			return $this->sendItemTimelineEvent($timeline->getItem(), $eventName, $eventParams);
		}

		return false;
	}

	public function sendTimelineDeleteEvent(int $typeId, int $itemId, int $timelineId, string $eventId = ''): bool
	{
		if($typeId > 0 && $itemId > 0)
		{
			$eventName = static::getEventName(static::EVENT_TIMELINE, $typeId, $itemId);
			$type = Driver::getInstance()->getType($typeId);

			return $this->sendItemTimelineEvent($type ? $type->getItem($itemId) : null, $eventName, [
				'command' => 'delete',
				'timeline' => [
					'id' => $timelineId,
				],
				'eventId' => $eventId,
			]);
		}

		return false;
	}

	public function sendItemAddedEvent(Item $item, string $eventId = ''): bool
	{
		$eventName = static::getEventName(static::EVENT_ITEM_ADDED, $item->getType()->getId());
		$eventParams = $this->prepareItemEventParams($item, $eventId);
		$this->sendKanbanEvent($item, $eventName, $eventParams);

		return $this->sendItemEvent(
			$item,
			$eventName,
			$eventParams,
			$this->getSubscribedUserIdsWithItemPermissions($item, $eventName),
		);
	}

	public function sendItemUpdatedEvent(Item $item, string $eventId = '', Item $historyItem = null): bool
	{
		$eventName = static::getEventName(static::EVENT_ITEM_UPDATED, $item->getType()->getId(), $item->getId());
		if(empty($eventId))
		{
			$eventId = $this->getItemUpdateEventId($item->getType()->getId(), $item->getId());
		}
		$eventParams = $this->prepareItemEventParams($item, $eventId, $historyItem);
		$kanbanUserIds = $this->getSubscribedUserIdsWithItemPermissions(
			$item,
			$this->getKanbanTag($item->getType()->getId()),
		);
		$this->sendKanbanEvent($item, $eventName, $eventParams, $kanbanUserIds);
		$this->sendKanbanItemRemovedEvent($item, $eventId, $kanbanUserIds);

		return $this->sendItemEvent(
			$item,
			$eventName,
			$eventParams,
			$this->getSubscribedUserIdsWithItemPermissions($item, $eventName),
		);
	}

	public function sendItemSortedEvent(Item $item, int $userId, int $previousItemId, string $eventId = ''): bool
	{
		$tag = $this->getKanbanTag($item->getType()->getId());
		if(!in_array($userId, $this->getSubscribedUserIdsWithItemPermissions($item, $tag), true))
		{
			return false;
		}
		$params = $this->prepareItemEventParams($item, $eventId);
		$params['eventName'] = static::getEventName(static::EVENT_ITEM_UPDATED, $item->getType()->getId(), $item->getId());
		$params['previousItemId'] = $previousItemId;

		return $this->sendEvent($tag, $params, [$userId]);
	}

	public function sendItemDeletedEvent(Item $item, string $eventId = ''): bool
	{
		$eventName = static::getEventName(static::EVENT_ITEM_DELETED, $item->getType()->getId(), $item->getId());
		$eventParams = $this->prepareItemEventParams($item, $eventId);
		$this->sendKanbanEvent($item, $eventName, $eventParams);

		return $this->sendEvent(
			$eventName,
			$eventParams,
			$this->getSubscribedUserIdsWithItemPermissions($item, $eventName),
		);
	}

	public function sendRobotAddedEvent(int $typeId, int $stageId, array $data = []): bool
	{
		$eventName = static::getEventName(static::EVENT_ROBOT_ADDED, $typeId);
		$eventParams = $this->prepareRobotEventParams($stageId, $data);
		$this->sendKanbanEvent($typeId, $eventName, $eventParams);

		return $this->sendEvent($eventName, $eventParams);
	}

	public function sendRobotUpdatedEvent(int $typeId, int $stageId, array $data = []): bool
	{
		if(empty($data['robotName']) || !is_string($data['robotName']))
		{
			return false;
		}

		$eventName = static::getEventName(static::EVENT_ROBOT_UPDATED, $typeId, $data['robotName']);
		$eventParams = $this->prepareRobotEventParams($stageId, $data);
		$this->sendKanbanEvent($typeId, $eventName, $eventParams);

		return $this->sendEvent($eventName, $eventParams);
	}

	public function sendRobotDeletedEvent(int $typeId, int $stageId, string $robotName, string $eventId = ''): bool
	{
		$eventName = static::getEventName(static::EVENT_ROBOT_DELETED, $typeId, $robotName);
		$eventParams = $this->prepareRobotEventParams($stageId, [
			'robotName' => $robotName,
		], $eventId);
		$this->sendKanbanEvent($typeId, $eventName, $eventParams);

		return $this->sendEvent($eventName, $eventParams);
	}

	public function sendTypeUpdatedEvent(Type $type, string $eventId = ''): bool
	{
		$eventName = static::getEventName(static::EVENT_TYPE_UPDATED, $type->getId());
		$eventParams = $this->prepareTypeEventParams($type, $eventId);
		$this->sendKanbanEvent($type, $eventName, $eventParams);

		return $this->sendEvent($eventName, $eventParams);
	}

	public function sendStageAddedEvent(Stage $stage, string $eventId = ''): bool
	{
		$eventName = static::getEventName(static::EVENT_STAGE_ADDED, $stage->getTypeId());
		$eventParams = $this->prepareStageEventParams($stage, $eventId);
		$this->sendKanbanEvent($stage, $eventName, $eventParams);

		return $this->sendEvent($eventName, $eventParams);
	}

	public function sendStageUpdatedEvent(Stage $stage, string $eventId = ''): bool
	{
		$eventName = static::getEventName(static::EVENT_STAGE_UPDATED, $stage->getTypeId(), $stage->getId());
		$eventParams = $this->prepareStageEventParams($stage, $eventId);
		$this->sendKanbanEvent($stage, $eventName, $eventParams);

		return $this->sendEvent($eventName, $eventParams);
	}

	public function sendStageDeletedEvent(int $stageId, int $typeId, string $eventId = ''): bool
	{
		$eventName = static::getEventName(static::EVENT_STAGE_DELETED, $typeId, $stageId);
		$eventParams = [
			'eventId' => $eventId,
			'stage' => [
				'id' => $stageId,
			]
		];
		$this->sendKanbanEvent($typeId, $eventName, $eventParams);

		return $this->sendEvent($eventName, $eventParams);
	}

	public function sendTaskCountersEvent(int $typeId, int $itemId, array $counters): bool
	{
		if(!$this->isEnabled())
		{
			return false;
		}

		$eventId = $this->getItemUpdateEventId($typeId, $itemId);
		$eventParams = [
			'itemId' => $itemId,
			'typeId' => $typeId,
			'eventId' => $eventId,
		];
		$taskManager = Driver::getInstance()->getTaskManager();
		if($taskManager)
		{
			$eventParams['tasksFaces'] = $taskManager->getItemFaces($typeId, $itemId);
		}
		$eventName = static::EVENT_TASK_COUNTERS;
		foreach($counters as $userId => $counter)
		{
			$eventParams['counter'] = $counter;
			$this->sendEvent($eventName, $eventParams, [$userId]);
		}

		return true;
	}

	protected function sendKanbanEvent($object, string $eventName, array $params = [], ?array $itemUserIds = null): bool
	{
		$typeId = $this->getTypeIdByModel($object);
		if($typeId !== null)
		{
			$params['eventName'] = $eventName;
			$userIds = null;
			if($object instanceof Item)
			{
				$userIds = $itemUserIds
					?? $this->getSubscribedUserIdsWithItemPermissions($object, $this->getKanbanTag($typeId))
				;

				return $this->sendItemEvent($object, $this->getKanbanTag($typeId), $params, $userIds);
			}

			return $this->sendEvent($this->getKanbanTag($typeId), $params, $userIds);
		}

		return false;
	}
	/**
	 * Remembers kanban subscribers who can view the item in its saved state; call it before the item is saved.
	 */
	public function rememberItemViewers(Item $item): void
	{
		$itemId = (int)$item->getId();
		if(!$this->isEnabled() || $itemId <= 0)
		{
			return;
		}
		$typeId = $item->getType()->getId();
		$this->itemViewerIds[$typeId][$itemId] = $this->getSubscribedUserIdsWithItemPermissions(
			$item,
			$this->getKanbanTag($typeId),
		);
	}

	/**
	 * Kanban subscribers who saw the item before the change and do not see it now get only the removal of the card,
	 * without any data of the item.
	 */
	protected function sendKanbanItemRemovedEvent(Item $item, string $eventId, array $viewerIds): bool
	{
		$typeId = $item->getType()->getId();
		$itemId = (int)$item->getId();
		$previousViewerIds = $this->itemViewerIds[$typeId][$itemId] ?? [];
		unset($this->itemViewerIds[$typeId][$itemId]);

		$lostViewerIds = array_values(array_diff(
			array_map('intval', $previousViewerIds),
			array_map('intval', $viewerIds),
		));
		if(empty($lostViewerIds))
		{
			return false;
		}

		return $this->sendEvent($this->getKanbanTag($typeId), [
			'eventName' => static::getEventName(static::EVENT_ITEM_DELETED, $typeId, $itemId),
			'eventId' => $eventId,
			'item' => [
				'id' => $itemId,
				'typeId' => $typeId,
			],
		], $lostViewerIds);
	}
	//endregion

	protected function prepareItemEventParams(Item $item, string $eventId = '', Item $historyItem = null): array
	{
		$controller = new \Bitrix\Rpa\Controller\Item();
		// Keep dates neutral until they are formatted for each recipient.
		$controller->setScope($controller::SCOPE_CLI);
		$data = $controller->prepareItemData($item, [
			'withDisplay' => true,
			'withTasks' => true,
			'withUsers' => true,
			'withPermissions' => true,
		]);
		return [
			'eventId' => $eventId,
			'item' => $data,
			'itemChangedUserFieldNames' => $historyItem ? $historyItem->getChangedUserFieldNames() : null
		];
	}

	protected function sendItemEvent(Item $item, string $eventName, array $params, ?array $userIds = null): bool
	{
		$userIds ??= $this->getSubscribedUserIds($eventName);
		if (empty($userIds))
		{
			return false;
		}

		$settings = \CTimeZone::Enabled() ? $this->getUserTimeZoneSettings($userIds) : [];
		$dates = [
			'createdTime' => $item->getCreatedTime(),
			'updatedTime' => $item->getUpdatedTime(),
			'movedTime' => $item->getMovedTime(),
		];
		$dateGroups = [];
		foreach ($userIds as $userId)
		{
			$dateValues = [];
			foreach ($dates as $field => $date)
			{
				$dateValues[$field] = $this->prepareUserDateTimeValue($date, (int)$userId, $settings[$userId] ?? []);
			}
			$key = serialize($dateValues);
			$dateGroups[$key] ??= [
				'dates' => $dateValues,
				'userIds' => [],
			];
			$dateGroups[$key]['userIds'][] = $userId;
		}

		$sent = false;
		foreach ($dateGroups as $group)
		{
			$userParams = $params;
			$userParams['item'] = array_replace($params['item'], $group['dates']);
			$sent = $this->sendEvent($eventName, $userParams, $group['userIds']) || $sent;
		}

		return $sent;
	}

	/**
	 * Sends the event of the item feed to subscribers who can view the item; without the item nobody gets it.
	 */
	protected function sendItemTimelineEvent(?Item $item, string $eventName, array $params): bool
	{
		if (!$item)
		{
			return false;
		}

		return $this->sendEvent(
			$eventName,
			$params,
			$this->getSubscribedUserIdsWithItemPermissions($item, $eventName),
		);
	}

	protected function getUserTimeZoneSettings(array $userIds): array
	{
		$settings = [];
		$users = UserTable::query()
			->setSelect(['ID', 'TIME_ZONE', 'TIME_ZONE_OFFSET'])
			->whereIn('ID', $userIds)
			->exec()
		;
		while ($user = $users->fetch())
		{
			$settings[(int)$user['ID']] = $user;
		}

		return $settings;
	}

	protected function prepareUserDateTimeValue(?DateTime $date, int $userId, array $settings): ?string
	{
		if ($date === null)
		{
			return null;
		}

		$date = clone $date;
		if ($date->isUserTimeEnabled() && \CTimeZone::Enabled())
		{
			$date->setDefaultTimeZone();
			if ($userId === Driver::getInstance()->getUserId())
			{
				$offset = \CTimeZone::GetOffset($userId, false, $date);
			}
			else
			{
				if (empty($settings['TIME_ZONE']))
				{
					$offset = (int)($settings['TIME_ZONE_OFFSET'] ?? 0);
				}
				else
				{
					$offset = \CTimeZone::getTimezoneOffset($settings['TIME_ZONE'], $date);
				}
			}
			if ($offset !== 0)
			{
				$offset += $date->getOffset();
				$date->setTimeZone(new \DateTimeZone('UTC'));
				$date->add(($offset < 0 ? '-' : '') . 'PT' . abs($offset) . 'S');
			}
		}

		return $date->format(DateTime::getFormat());
	}

	protected function prepareTypeEventParams(Type $type, string $eventId): array
	{
		$controller = new \Bitrix\Rpa\Controller\Type();
		$data = $controller->prepareData($type);
		return [
			'eventId' => $eventId,
			'type' => $data,
		];
	}

	protected function getSubscribedUserIds(string $eventName): array
	{
		if (!$this->isEnabled())
		{
			return [];
		}

		return $this->getWatchingUserIds($eventName);
	}

	protected function getWatchingUserIds(string $tag): array
	{
		return WatchTable::getUserIdsByTag($tag);
	}

	protected function getSubscribedUserIdsWithItemPermissions(Item $item, string $eventName): array
	{
		if(!$this->isEnabled())
		{
			return [];
		}
		$userIds = $this->getWatchingUserIds($eventName);
		return UserPermissions::filterUserIdsWhoCanViewItem($item, $userIds);
	}

	protected function getSubscribedUserIdsWithTypePermissions(int $typeId, string $eventName): array
	{
		if(!$this->isEnabled())
		{
			return [];
		}
		$userIds = $this->getWatchingUserIds($eventName);
		return UserPermissions::filterUserIdsWhoCanViewType($typeId, $userIds);
	}

	protected function getKanbanTag(int $typeId): string
	{
		return static::getEventName(static::EVENT_KANBAN_UPDATED, $typeId);
	}

	protected function prepareStageEventParams(Stage $stage, $eventId = ''): array
	{
		$controller = new \Bitrix\Rpa\Controller\Stage();
		$data = $controller->prepareData($stage);
		return [
			'eventId' => $eventId,
			'stage' => $data,
		];
	}

	protected function getTypeIdByModel($object): ?int
	{
		if(is_numeric($object))
		{
			return (int)$object;
		}

		if($object instanceof Item)
		{
			return $object->getType()->getId();
		}

		if($object instanceof Stage)
		{
			return $object->getType()->getId();
		}

		if($object instanceof Type)
		{
			return $object->getId();
		}

		return null;
	}

	protected function subscribeOnEvent(string $eventName, bool $immediate = true): ?string
	{
		if($this->isEnabled && !empty($eventName))
		{
			$addResult = \CPullWatch::add(Driver::getInstance()->getUserId(), $eventName, $immediate);
			if($addResult)
			{
				return $eventName;
			}
		}

		return null;
	}

	protected function sendEvent(string $eventName, array $params = [], array $userIds = null): bool
	{
		if(!$this->isEnabled())
		{
			return false;
		}
		if(is_array($userIds))
		{
			if(!empty($userIds))
			{
				return Event::add($userIds, [
					'module_id' => Driver::MODULE_ID,
					'command' => $eventName,
					'params' => $params,
				]);
			}
		}
		else
		{
			return \CPullWatch::AddToStack($eventName, [
				'module_id' => Driver::MODULE_ID,
				'command' => $eventName,
				'params' => $params,
			]);
		}

		return false;
	}

	protected function prepareRobotEventParams(int $stageId, array $data = [], string $eventId = ''): array
	{
		return [
			'robot' => array_merge($data, ['stageId' => $stageId]),
			'eventId' => $eventId,
		];
	}

	protected static function getEventName(string $eventName, int $typeId, $item = ''): string
	{
		$eventName .= $typeId;
		if(!empty($item) && (is_string($item) || is_numeric($item)))
		{
			$eventName .= '_'.$item;
		}

		return $eventName;
	}

	protected function prepareTimelineEventParams(Timeline $timeline, string $command, string $eventId = ''): array
	{
		return [
			'command' => $command,
			'timeline' => $timeline->preparePublicData(),
			'eventId' => $eventId,
		];
	}

	public function addItemUpdateEventId(int $typeId, int $itemId, string $eventId): PullManager
	{
		$this->eventIds['item_update'][$typeId][$itemId] = $eventId;

		return $this;
	}

	protected function getItemUpdateEventId(int $typeId, int $itemId): string
	{
		return $this->eventIds['item_update'][$typeId][$itemId] ?? '';
	}
}
