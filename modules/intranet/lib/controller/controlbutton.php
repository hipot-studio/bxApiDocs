<?php

namespace Bitrix\Intranet\Controller;

use Bitrix\Disk\File;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Application;
use Bitrix\Intranet;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialnetwork\Helper;
use Bitrix\Socialnetwork\Integration\Im\Chat\Workgroup;
use Bitrix\Socialnetwork\UserToGroupTable;

class ControlButton extends \Bitrix\Main\Engine\Controller
{
	protected const SIGNATURE_SALT = 'control_button_salt';

	public function getAvailableItemsAction(): array
	{
		$items = [];

		if (ModuleManager::isModuleInstalled('im'))
		{
			$items[] = 'chat';
			$items[] = 'videocall';
		}

		if (ModuleManager::isModuleInstalled('socialnetwork'))
		{
			$items[] = 'blog_post';
		}

		if (ModuleManager::isModuleInstalled('tasks'))
		{
			$items[] = 'task';
		}

		if (ModuleManager::isModuleInstalled('calendar'))
		{
			$items[] = 'calendar_event';
		}

		return $items;
	}

	private function checkUsers(&$userIds): void
	{
		$newUserIds = [];
		$externalUserTypes = \Bitrix\Main\UserTable::getExternalUserTypes();

		$result = \Bitrix\Main\UserTable::getList([
			'select' => ['ID', 'EXTERNAL_AUTH_ID'],
			'filter' => ["=ID" => $userIds],
		]);
		while ($user = $result->fetch())
		{
			if (!in_array($user['EXTERNAL_AUTH_ID'], $externalUserTypes, true))
			{
				$newUserIds[] = (int)$user['ID'];
			}
		}

		$userIds = $newUserIds;
	}

	private function getTaskData($entityId): array
	{
		global $USER;

		if (!Loader::includeModule('tasks'))
		{
			return [];
		}

		if (
			!\Bitrix\Tasks\Access\TaskAccessController::can(
				$USER->GetID(),
				\Bitrix\Tasks\Access\ActionDictionary::ACTION_TASK_READ,
				$entityId
			)
		)
		{
			return [];
		}

		$query = new Query(\Bitrix\Tasks\Internals\TaskTable::getEntity());
		$query->setSelect([
			'ID',
			'TITLE',
			'DESCRIPTION',
			'DEADLINE',
			'STATUS',
			'CREATED_BY',
			'GROUP_ID',
			'TM_USER_ID' => 'TM.USER_ID',
			//'TM_TYPE' => 'TM.TYPE',
		]);
		$query->setFilter([
			'=ID' => $entityId,
		]);

		$query->registerRuntimeField('', new ReferenceField(
			'TM',
			\Bitrix\Tasks\Internals\Task\MemberTable::getEntity(),
			['=ref.TASK_ID' => 'this.ID']
		));

		$res = $query->exec();

		$task = [
			'USER_IDS' => [],
		];

		while ($item = $res->fetch())
		{
			$task['ID'] = $item['ID'];
			$task['TITLE'] = $item['TITLE'];
			$task['DESCRIPTION'] = $item['DESCRIPTION'];
			$task['CREATED_BY'] = $item['CREATED_BY'];
			$task['GROUP_ID'] = $item['GROUP_ID'];
			$task['LINK'] = SITE_DIR . 'company/personal/user/' . $item['CREATED_BY'] . '/tasks/task/view/' . $item['ID'] . '/';

			$userId = (int)$item['TM_USER_ID'];
			$userType = $item['TM_TYPE'] ?? null;

			unset($item['TM_USER_ID'], $item['TM_TYPE']);

			$task['SE_MEMBER'][$userId] = ['USER_ID' => $userId, 'TYPE' => $userType];
			if (!in_array($userId, $task['USER_IDS'], true))
			{
				$task['USER_IDS'][] = $userId;
			}

			/*$roleMap = ['O' => 'CREATED_BY', 'R' => 'RESPONSIBLE_ID'];
			if (array_key_exists($userType, $roleMap))
			{
				$tasks[$taskId][$roleMap[$userType]] = $userId;
			}*/
		}

		$this->checkUsers($task['USER_IDS']);

		return $task;
	}

	public function getCalendarData($entityId, $entityData = []): array
	{
		global $USER;

		$res = [];

		if (!Loader::includeModule('calendar'))
		{
			return $res;
		}

		$entry = $this->readCalendarEvent($entityId);

		if (!$entry)
		{
			return $res;
		}

		$pathToCalendar = \CCalendar::GetPathForCalendarEx($USER->GetID());
		$pathToEvent = (string)(new Uri($pathToCalendar))->addParams(['EVENT_ID' => $entry['ID']]);

		$attendeeList = $this->getCalendarAttendeeList($entry);

		$notDeclinedIds = null;
		if (is_array($attendeeList))
		{
			// exclude decliners
			$notDeclinedIds =  array_column(
				array_filter(
					$attendeeList,
					static fn (array $attendee) => $attendee['status'] !== 'N'
				),
				'id'
			);
		}

		$res = [
			'ID' => $entry['ID'],
			'PARENT_ID' => $entry['PARENT_ID'] ?? 0,
			'TITLE' => $entry['NAME'],
			'DESCRIPTION' => $entry['DESCRIPTION'],
			'CREATED_BY' => $entry['CREATED_BY'],
			'MEETING' => $entry['MEETING'],
			'DATE_FROM' => $entry['DATE_FROM'],
			'DT_SKIP_TIME' => $entry['DT_SKIP_TIME'],
			'RECURRENCE_ID' => $entry['RECURRENCE_ID'],
			// TODO: not used if NOT_DECLINED_IDS presented
			'USER_IDS' => is_array($attendeeList)
				? array_column($attendeeList, 'id')
				: [$entry['CREATED_BY']]
			,
			'NOT_DECLINED_IDS' => $notDeclinedIds ?: [$entry['CREATED_BY']],
			'LINK' => $pathToEvent,
			'URL' => $pathToEvent,
		];

		$this->checkUsers($res['USER_IDS']);
		$this->checkUsers($res['NOT_DECLINED_IDS']);

		return $res;
	}

	protected function readCalendarEvent($entityId): array|false
	{
		return \CCalendarEvent::getEventForViewInterface($entityId);
	}

	private function getCalendarAttendeeList(array $entry): ?array
	{
		$attendeeList = $entry['ATTENDEE_LIST'] ?? null;
		if (!is_array($attendeeList))
		{
			return null;
		}

		if (!empty($attendeeList) || !$this->isOwnPlainEventHost($entry))
		{
			return $attendeeList;
		}

		$status = in_array($entry['MEETING_STATUS'] ?? null, ['Y', 'N', 'Q', 'H'], true)
			? $entry['MEETING_STATUS']
			: 'H'
		;

		return [
			[
				'id' => (int)$entry['MEETING_HOST'],
				'entryId' => $entry['ID'],
				'status' => $status,
			],
		];
	}

	/**
	 * MEETING_HOST comes from the client, so it is trusted only when it matches the calendar owner.
	 */
	private function isOwnPlainEventHost(array $entry): bool
	{
		$hostId = (int)($entry['MEETING_HOST'] ?? 0);

		return
			empty($entry['IS_MEETING'])
			&& ($entry['CAL_TYPE'] ?? null) === 'user'
			&& $hostId > 0
			&& $hostId === (int)($entry['OWNER_ID'] ?? 0)
		;
	}

	private function getTaskChat($entityId)
	{
		$chatId = '';

		global $USER;
		$userId = $USER->GetID();

		if (
			!Loader::includeModule('tasks')
			|| !Loader::includeModule('im')
			|| !\Bitrix\Tasks\Access\TaskAccessController::can(
				$userId,
				\Bitrix\Tasks\Access\ActionDictionary::ACTION_TASK_READ,
				$entityId
			)
		)
		{
			return $chatId;
		}

		$taskData = $this->getTaskData($entityId);

		$res = \Bitrix\Im\Model\ChatTable::getList(array(
			'select' => ['ID'],
			'filter' => [
				'=ENTITY_TYPE' => 'TASKS',
				'=ENTITY_ID' => $entityId,
			],
			'limit' => 1
		));

		if ($chat = $res->fetch())
		{
			$chatId = $chat['ID'];
			Intranet\ControlButton::addUserToChat($chatId, $userId);
		}
		else
		{
			$lockName = "chat_create_task_{$entityId}";
			if (!Application::getConnection()->lock($lockName))
			{
				$this->addError(new Error(
						Loc::getMessage('INTRANET_CONTROL_BUTTON_CREATE_CHAT_LOCK_ERROR'), 'lock_error')
				);
				return null;
			}

			$chatId = Intranet\ControlButton::createTaskChat($taskData, $userId);

			Application::getConnection()->unlock($lockName);
		}

		return $chatId;
	}

	private function getCalendarChat($entityId, $entityData = [])
	{
		global $USER;

		$chatId = '';

		if (!Loader::includeModule('calendar') || !Loader::includeModule('im'))
		{
			return $chatId;
		}

		$userId = (int)$USER->GetId();
		$calendarData = $this->getCalendarData($entityId, $entityData);

		if (
			!is_array($calendarData['USER_IDS'])
			|| !in_array($userId, $calendarData['USER_IDS'])
		)
		{
			return $chatId;
		}

		$canonicalEntityId = (int)($calendarData['PARENT_ID'] ?: $calendarData['ID']);
		if ($canonicalEntityId !== (int)$calendarData['ID'])
		{
			$calendarData = $this->getCalendarData($canonicalEntityId);
			if (
				empty($calendarData['ID'])
				|| !is_array($calendarData['USER_IDS'] ?? null)
				|| !in_array($userId, $calendarData['USER_IDS'], true)
			)
			{
				return $chatId;
			}
		}

		if ($calendarData['MEETING']['CHAT_ID'] > 0)
		{
			$chatId = $calendarData['MEETING']['CHAT_ID'];
			Intranet\ControlButton::addUserToChat($chatId, $userId);
		}
		else
		{
			if (class_exists(\Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver::class))
			{
				$lockName = \Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver::getChatCreateLockName(
					$canonicalEntityId,
					(int)($calendarData['RECURRENCE_ID'] ?? 0),
				);
				$locked = Application::getConnection()->lock(
					$lockName,
					\Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver::CHAT_CREATE_LOCK_TIMEOUT,
				);
			}
			else
			{
				// calendar without the guest link feature never takes this lock, so the name needs no sharing
				$lockName = "chat_create_calendar_event_{$canonicalEntityId}";
				$locked = Application::getConnection()->lock($lockName);
			}

			if (!$locked)
			{
				$this->addError(new Error(
					Loc::getMessage('INTRANET_CONTROL_BUTTON_CREATE_CHAT_LOCK_ERROR'), 'lock_error')
				);
				return null;
			}

			try
			{
				$calendarData = $this->getCalendarData($canonicalEntityId);
				if (
					empty($calendarData['ID'])
					|| !is_array($calendarData['USER_IDS'] ?? null)
					|| !in_array($userId, $calendarData['USER_IDS'], true)
				)
				{
					return $chatId;
				}

				$chatId = (int)($calendarData['MEETING']['CHAT_ID'] ?? 0);
				if ($chatId > 0)
				{
					Intranet\ControlButton::addUserToChat($chatId, $userId);
				}
				else
				{
					$parentCalendarData = [];
					if ($calendarData['RECURRENCE_ID'])
					{
						$parentCalendarData = $this->getCalendarData($calendarData['RECURRENCE_ID']);
					}

					$chatId = Intranet\ControlButton::createCalendarChat($calendarData, $userId, $parentCalendarData);
				}
			}
			finally
			{
				Application::getConnection()->unlock($lockName);
			}
		}

		return $chatId;
	}

	private function getWorkgroupChat($groupId, $entityData = [])
	{
		$chatId = '';

		if (
			!Loader::includeModule('socialnetwork')
			|| !Loader::includeModule('im')
		)
		{
			return $chatId;
		}

		$groupId = (int)$groupId;

		if ($groupId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROL_BUTTON_ENTITY_ERROR')));

			return null;
		}

		if (!$this->ensureWorkgroupChatAccess($groupId))
		{
			return null;
		}

		$chatData = Workgroup::getChatData(
			[
				'group_id' => $groupId,
				'skipAvailabilityCheck' => true,
			]
		);
		if (!empty($chatData[$groupId]) && intval($chatData[$groupId]) > 0)
		{
			$chatId = $chatData[$groupId];
		}
		else
		{
			$chatId = Workgroup::createChat(
				[
					'group_id' => $groupId,
				]
			);
		}

		return $chatId;
	}

	private function ensureWorkgroupChatAccess(int $groupId): bool
	{
		global $USER;
		$userId = (is_object($USER) ? (int)$USER->getId() : 0);

		if ($this->canAccessWorkgroupChat($groupId, $userId))
		{
			return true;
		}

		$this->addError(new Error(
			Loc::getMessage('INTRANET_CONTROL_BUTTON_WORKGROUP_ACCESS_ERROR'),
			'access_denied'
		));

		return false;
	}

	/**
	 * Group chat belongs to group members only, so UserCanViewGroup is not enough here:
	 * it is also true for a non-member of a visible open group.
	 * UserCanModifyGroup covers the owner, the scrum master and the portal admin.
	 *
	 * Membership is resolved for $userId, but the portal admin flag inside
	 * CSocNetUserToGroup::InitUserPerms always comes from the current session, not from $userId.
	 */
	protected function canAccessWorkgroupChat(int $groupId, int $userId): bool
	{
		if ($groupId <= 0 || $userId <= 0)
		{
			return false;
		}

		$permissions = Helper\Workgroup::getPermissions([
			'groupId' => $groupId,
			'userId' => $userId,
		]);

		return !empty($permissions['UserIsMember']) || !empty($permissions['UserCanModifyGroup']);
	}

	public function getChatAction($entityType, $entityId, $entityData = [])
	{
		if (!$entityType || !$entityId)
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROL_BUTTON_ENTITY_ERROR')));
			return null;
		}

		if (!Loader::includeModule('im'))
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROL_BUTTON_IM_ERROR'), 'create_chat_error'));
			return null;
		}

		$chatId = '';

		if ($entityType === 'task')
		{
			$chatId = $this->getTaskChat($entityId);
		}
		elseif ($entityType === 'calendar_event')
		{
			$chatId = $this->getCalendarChat($entityId, $entityData);
		}
		elseif ($entityType === 'workgroup')
		{
			$chatId = $this->getWorkgroupChat($entityId, $entityData);
		}

		return $chatId;
	}

	public function getVideoCallChatAction($entityType, $entityId, $entityData = [])
	{
		$userCount = $this->getVideoCallUserCount($entityType, $entityId, $entityData);
		if ($userCount === null)
		{
			return null;
		}

		if ($userCount === 1)
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROL_BUTTON_VIDEOCALL_SELF_ERROR')));
			return null;
		}

		return $this->getChatAction($entityType, $entityId);
	}

	public function getVideoCallStartAction($entityType, $entityId, $entityData = []): ?array
	{
		$userCount = $this->getVideoCallUserCount($entityType, $entityId, $entityData);
		if ($userCount === null)
		{
			return null;
		}

		$callOptions = [];
		if ($userCount === 1)
		{
			if ($entityType !== 'calendar_event')
			{
				$this->addError(new Error(Loc::getMessage('INTRANET_CONTROL_BUTTON_VIDEOCALL_SELF_ERROR')));
				return null;
			}

			// a meeting may be started by its only employee, guests are invited by link and need time to join;
			// an older call module has no such period, then the call waits for the usual time
			if ($this->isSyncCallInvitePeriodAvailable())
			{
				$callOptions = ['invitePeriod' => \Bitrix\Call\Settings::getSyncCallInvitePeriod()];
			}
		}

		$chatId = (int)$this->getChatAction($entityType, $entityId);
		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		// without a chat there is no call, and the options would reveal to a non-member that the event is personal
		if ($chatId <= 0)
		{
			$callOptions = [];
		}

		return [
			'chatId' => $chatId,
			'callOptions' => $callOptions,
		];
	}

	protected function isSyncCallInvitePeriodAvailable(): bool
	{
		return method_exists(\Bitrix\Call\Settings::class, 'getSyncCallInvitePeriod');
	}

	/**
	 * Returns null when the request is rejected, the error is already added.
	 */
	private function getVideoCallUserCount($entityType, $entityId, $entityData): ?int
	{
		if (!$entityType || !$entityId)
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROL_BUTTON_ENTITY_ERROR')));
			return null;
		}

		if (!Loader::includeModule('im') || !Loader::includeModule('call'))
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROL_BUTTON_IM_ERROR'), 'create_chat_error'));
			return null;
		}

		$userCount = $this->countVideoCallUsers((string)$entityType, $entityId, $entityData);
		if ($userCount === null)
		{
			return null;
		}

		if ($userCount > \Bitrix\Call\Call::getMaxParticipants())
		{
			$this->addError(new Error(Loc::getMessage('INTRANET_CONTROL_BUTTON_VIDEOCALL_LIMIT')));
			return null;
		}

		return $userCount;
	}

	/**
	 * Returns null when access is denied, the error is already added.
	 */
	protected function countVideoCallUsers(string $entityType, $entityId, $entityData): ?int
	{
		$userCount = 0;

		if ($entityType === 'task')
		{
			$taskData = $this->getTaskData($entityId);

			if (is_array($taskData['USER_IDS']))
			{
				$userCount = count($taskData['USER_IDS']);
			}
		}
		elseif ($entityType === 'calendar_event')
		{
			$calendarData = $this->getCalendarData($entityId, $entityData);

			if (is_array($calendarData['USER_IDS']))
			{
				$userCount = count($calendarData['USER_IDS']);
			}
		}
		elseif ($entityType === 'workgroup' && Loader::includeModule('socialnetwork'))
		{
			$groupId = (int)$entityId;

			if (!$this->ensureWorkgroupChatAccess($groupId))
			{
				return null;
			}

			$userCount = Workgroup::getNumberOfMembers($groupId);
		}

		return $userCount;
	}

	public function getTaskLinkAction($entityType, $entityId, $postEntityType = '', $entityData = []): array
	{
		global $USER;

		$result = [
			'link' => '',
			'TITLE' => '',
			'DESCRIPTION' => '',
			'AUDITORS' => '',
		];

		if (!$entityType || !$entityId)
		{
			return $result;
		}

		switch (strtolower($entityType))
		{
			case 'calendar_event':
				$data = $this->getCalendarData($entityId, $entityData);
				break;
			default:

				if (
					!Loader::includeModule('socialnetwork')
					|| !Loader::includeModule('tasks')
				)
				{
					return $result;
				}

				$data = [];

				if ($provider = \Bitrix\Socialnetwork\Livefeed\Provider::init([
					'ENTITY_TYPE' => $entityType,
					'ENTITY_ID' => $entityId,
					'CLONE_DISK_OBJECTS' => true,
				]))
				{
					$data = [
						'TITLE' => $provider->getSourceTitle(),
						'DESCRIPTION' => $provider->getSourceDescription(),
						'SUFFIX' => $provider->getSuffix(),
						'URL' => $provider->getLiveFeedUrl(),
						'DISK_FILES' => array_values($provider->getAttachedDiskObjectsCloned()),
						'SONET_GROUP_ID' => $provider->getSonetGroupsAvailable(),
					];
				}
		}

		if (!empty($data))
		{
			$link = str_replace(
				[ '#USER_ID#', '#ID#' ],
				$USER->getId(),
				Option::get('intranet', 'search_user_url', SITE_DIR . 'company/personal/user/#USER_ID#/')
			) . 'tasks/task/edit/0/';

			$section = match ($entityType)
			{
				'calendar_event' => 'calendar',
				'workgroup' => 'project',
				default => 'feed',
			};

			$result['link'] = (new Uri($link))
				->addParams([
					'ta_sec' => $section,
					'ta_el' => 'context_menu',
				])
				->getUri()
			;

			$result['TITLE'] = $data['TITLE'];
			$result['DESCRIPTION'] = $data['DESCRIPTION'];
			$result['URL'] = $data['URL'];

			if (isset($data['USER_IDS']) && !empty($data['USER_IDS']))
			{
				$result['AUDITORS'] = implode(',', $data['USER_IDS']);
			}

			if ($entityType === 'calendar_event')
			{
				$result['CALENDAR_EVENT_ID'] = $data['ID'];
				$result['CALENDAR_EVENT_DATA'] = $entityData;
			}
			else
			{
				$result['SOURCE_POST_ENTITY_TYPE'] = $postEntityType;
				$result['SOURCE_ENTITY_TYPE'] = $entityType;
				$result['SOURCE_ENTITY_ID'] = (int)$entityId;
				$result['SUFFIX'] = $data['SUFFIX'];

				if (!empty($data['DISK_FILES']))
				{
					$diskFileUFCode = \Bitrix\Tasks\Integration\Disk\UserField::getMainSysUFCode();
					$result[$diskFileUFCode] = $data['DISK_FILES'];
					$signer = new Signer;
					$result[$diskFileUFCode . '_SIGN'] = $signer->sign(Json::encode($data['DISK_FILES']), static::SIGNATURE_SALT);
				}

				if (
					!empty($data['SONET_GROUP_ID'])
					&& count($data['SONET_GROUP_ID']) === 1
				)
				{
					$result['GROUP_ID'] = (int)array_pop($data['SONET_GROUP_ID']);
				}
			}
		}

		return $result;
	}

	public function clearNewTaskFilesAction(string $signedFiles = '')
	{
		if ($signedFiles === '')
		{
			return;
		}

		$signer = new Signer;

		try
		{
			$unsigned = $signer->unsign($signedFiles, static::SIGNATURE_SALT);
			$diskFiles = Json::decode($unsigned);
		}
		catch (\Exception $e)
		{
			$diskFiles = [];
		}

		if (
			!is_array($diskFiles)
			|| empty($diskFiles)
		)
		{
			return;
		}

		if (!Loader::includeModule('disk'))
		{
			$this->addError(new Error(
					Loc::getMessage('INTRANET_CONTROL_BUTTON_DISK_ERROR'), 'delete_new_task_files_no_module_error')
			);
			return null;
		}

		$fileIdList = array_map(static function($value) {
			return (
			preg_match('/^' . FileUserType::NEW_FILE_PREFIX . '(\d+)$/i', $value, $matches)
				? (int)$matches[1]
				: 0
			);
		}, $diskFiles);
		$fileIdList = array_filter($fileIdList, static function($value) {
			return ($value > 0);
		});
		$fileIdList = array_unique($fileIdList);

		foreach( File::getModelList([ 'filter' => [ 'ID' => $fileIdList ] ] ) as $file)
		{
			if (
				($storage = $file->getStorage())
				&& $file->canDelete($storage->getCurrentUserSecurityContext())
			)
			{
				if (!$file->delete($this->getCurrentUser()->getId()))
				{
					$this->addError(new Error(
							Loc::getMessage('INTRANET_CONTROL_BUTTON_DELETE_TASK_FILE_ERROR'), 'delete_new_task_file_error')
					);
					return null;
				}
			}
		}
	}

	public function getCalendarLinkAction($entityType, $entityId): array
	{
		if (!$entityType || !$entityId)
		{
			return [];
		}

		$res = [];

		if ($entityType === 'task')
		{
			$data = $this->getTaskData($entityId);

			$res = [
				'name' => $data['TITLE'],
				'desc' => Loc::getMessage('INTRANET_CONTROL_BUTTON_POST_MESSAGE_TASK', [
					'#LINK#' => $data['LINK'],
				]),
				'userIds' => $data['USER_IDS'],
			];
		}

		return $res;
	}

	public function getPostLinkAction($entityType, $entityId, $entityData = []): array
	{
		global $USER;

		$result = [
			'link' => '',
			'destTo' => [],
			'title' => '',
		];

		if (!$entityType || !$entityId)
		{
			return $result;
		}

		$result['link'] = SITE_DIR . 'company/personal/user/' . $USER->GetID() . '/blog/edit/post/0/';

		$data = false;

		if ($entityType === 'calendar_event')
		{
			$data = $this->getCalendarData($entityId, $entityData);
			$result['message'] = Loc::getMessage('INTRANET_CONTROL_BUTTON_POST_MESSAGE_CALENDAR', [
				'#LINK#' => $data['LINK'],
			]);
		}
		elseif ($entityType === 'task')
		{
			$data = $this->getTaskData($entityId);
			$result['message'] = Loc::getMessage('INTRANET_CONTROL_BUTTON_POST_MESSAGE_TASK', [
				'#LINK#' => $data['LINK'],
			]);
		}

		if (is_array($data))
		{
			foreach ($data['USER_IDS'] as $userId)
			{
				$result['destTo'][] = 'U' . (int)$userId;
			}

			$result['title'] = $data['TITLE'];

			if (isset($data['GROUP_ID']) && (int)$data['GROUP_ID'] > 0)
			{
				$result['destTo'][] = 'SG' . (int)$data['GROUP_ID'];
			}
		}

		return $result;
	}
}
