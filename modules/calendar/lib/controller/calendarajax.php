<?php
namespace Bitrix\Calendar\Controller;

use Bitrix\Bitrix24\Feature;
use Bitrix\Calendar\Access\EventAccessController;
use Bitrix\Calendar\Access\Model\SectionModel;
use Bitrix\Calendar\Access\Model\TypeModel;
use Bitrix\Calendar\Access\SectionAccessController;
use Bitrix\Calendar\Access\TypeAccessController;
use Bitrix\Calendar\Controller\Filter\RestrictExternalUser;
use Bitrix\Calendar\Core\Event\Event;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Core\Mappers\Factory;
use Bitrix\Calendar\Integration\Bitrix24\FeatureDictionary;
use Bitrix\Calendar\Integration\SocialNetwork\Collab\UserCollabs;
use Bitrix\Calendar\Integration\HumanResources;
use Bitrix\Calendar\Integration\SocialNetwork;
use Bitrix\Calendar\OpenEvents\Service\DefaultCategoryService;
use Bitrix\Calendar\OpenEvents\Service\OpenEventAttendeeService;
use Bitrix\Calendar\Internals\SectionTable;
use Bitrix\Calendar\OpenEvents\Controller\Request\OpenEvent\SetEventAttendeeStatusDto;
use Bitrix\Calendar\Rooms;
use Bitrix\Calendar\Sharing;
use Bitrix\Calendar\Ui\CalendarFilter;
use Bitrix\Calendar\Util;
use Bitrix\Calendar\View\EventViewData;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Calendar\UserSettings;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Calendar\Integration\Bitrix24Manager;
use Bitrix\Calendar\Ui\CountersManager;
use Bitrix\Calendar\Access\ActionDictionary;
use Bitrix\Calendar\Internals\Helper\Analytics;
use Bitrix\Main\Analytics\AnalyticsEvent;

Loc::loadMessages(__FILE__);

/**
 * Class CalendarAjax
 */
class CalendarAjax extends \Bitrix\Main\Engine\Controller
{
	public const EVENT_EDIT_FORM_FIELDS_THAT_CAN_BE_HIDDEN = [
		'repeatRule' => 'rrule',
		'crm' => 'crm',
		'accessibility' => 'accessibility',
	];

	public function configureActions()
	{
		return [
			'getTimezoneList' => [
				'-prefilters' => [
					Authentication::class,
				],
			],
			'editCalendarSection' => [
				'+prefilters' => [
					new RestrictExternalUser(),
				],
			],
			'deleteCalendarSection' => [
				'+prefilters' => [
					new RestrictExternalUser(),
				],
			],
			'hideExternalCalendarSection' => [
				'+prefilters' => [
					new RestrictExternalUser(),
				],
			],
		];
	}

	public function getTimezoneListAction()
	{
		$timezones = \CCalendar::getTimezoneList();
		$defaultTimezone = \CCalendar::getGoodTimezoneForOffset(\CCalendar::getCurrentOffsetUTC(\CCalendar::getCurUserId()));
		if (isset($timezones[$defaultTimezone]))
		{
			$timezones[$defaultTimezone]['default'] = true;
		}

		return $timezones;
	}

	public function editCalendarSectionAction()
	{
		$request = $this->getRequest();
		$response = [];

		$id = $request->getPost('id');
		$isNew = (!isset($id) || !$id);
		$type = $request->getPost('type');
		$ownerId = (int)$request->getPost('ownerId');
		$name = trim($request->getPost('name'));
		$color = $request->getPost('color');
		$customization = $request->getPost('customization') === 'Y';
		$userId = \CCalendar::GetUserId();

		if ($id === 'tasks')
		{
			$id .= $ownerId;
		}

		$fields = [
			'ID' => $id,
			'NAME' => $name,
			'COLOR' => $color,
			'CAL_TYPE' => $type,
			'OWNER_ID' => $ownerId,
			'ACCESS' => $request->getPost('access'),
			'EXTERNAL_TYPE' => $request->getPost('external_type') ?? 'local',
		];

		if ($customization && !$isNew)
		{
			UserSettings::setSectionCustomization($userId, [$id => ['name' => $name, 'color' => $color]]);
		}
		else
		{
			if (Loader::includeModule('intranet') && !\Bitrix\Intranet\Util::isIntranetUser())
			{
				if (
					$type === 'group'
					&& Loader::includeModule('socialnetwork')
				)
				{
					$rights = \Bitrix\Socialnetwork\UserToGroupTable::getList([
						'filter' => [
							'@ROLE' => \Bitrix\Socialnetwork\UserToGroupTable::getRolesMember(),
							'=GROUP_ID' => $ownerId,
							'=USER_ID' => $userId,
						],
						'limit' => 1,
					]);

					if (!$rights->fetch())
					{
						$this->addError(
							new Error(Loc::getMessage('EC_ACCESS_DENIED'), 'access_denied_extranet_group')
						);
					}
				}
				else if ($type === 'user')
				{
					if ($userId !== $ownerId)
					{
						$this->addError(
							new Error(Loc::getMessage('EC_ACCESS_DENIED'), 'access_denied_extranet_user')
						);
					}
				}
				else
				{
					$this->addError(
						new Error(Loc::getMessage('EC_ACCESS_DENIED'), 'access_denied_extranet')
					);
				}
			}

			if ($this->getErrors())
			{
				return $response;
			}

			$accessController = new SectionAccessController($userId);

			if ($isNew) // For new sections
			{
				$sectionModel =
					SectionModel::createNew()
						->setType($type ?? '')
						->setOwnerId($userId ?? 0);
				if (!$accessController->check(ActionDictionary::ACTION_SECTION_ADD, $sectionModel))
				{
					$this->addError(
						new Error(Loc::getMessage('EC_ACCESS_DENIED'), 'access_denied')
					);
				}

				if ($type === 'group' && Loader::includeModule('socialnetwork'))
				{
					$result = \Bitrix\Socialnetwork\UserToGroupTable::getList([
						'filter' => [
							'@ROLE' => \Bitrix\Socialnetwork\UserToGroupTable::getRolesMember(),
							'=GROUP_ID' => $ownerId,
							'=USER_ID' => $userId,
						],
					]);

					$group = $result->fetch();
					if (!$group)
					{
						$this->addError(
							new Error(Loc::getMessage('EC_ACCESS_DENIED'), 'access_denied_user')
						);
					}
				}

				$fields['IS_EXCHANGE'] = $request->getPost('is_exchange') === 'Y';
			}
			else
			{
				$section = \CCalendarSect::GetById($id);
				$sectionModel = SectionModel::createFromArray($section);

				if (
					!$section
					|| !$accessController->check(ActionDictionary::ACTION_SECTION_EDIT, $sectionModel, [])

				)
				{
					$this->addError(
						new Error(Loc::getMessage('EC_ACCESS_DENIED'), 'access_denied_04')
					);
				}

				$fields['CAL_TYPE'] = $section['CAL_TYPE'];
				$fields['OWNER_ID'] = $section['OWNER_ID'];
			}

			if (empty($this->getErrors()))
			{
				$id = \CCalendar::SaveSection(['arFields' => $fields]);
				if ((int)$id > 0)
				{
					\CCalendarSect::SetClearOperationCache(true);
					$response['section'] = \CCalendarSect::GetById($id, true, true);
					if (!$response['section'])
					{
						$this->addError(
							new Error(Loc::getMessage('EC_CALENDAR_SAVE_ERROR'), 'saving_error_05')
						);
					}
					$response['accessNames'] = \CCalendar::GetAccessNames();

					$response['sectionList'] = \CCalendarSect::prepareSectionListResponse($type, $ownerId);
				}
				else
				{
					$this->addError(
						new Error(Loc::getMessage('EC_CALENDAR_SAVE_ERROR'), 'saving_error_06')
					);
				}
			}
		}

		return $response;
	}

	public function hideExternalCalendarSectionAction()
	{
		$request = $this->getRequest();
		$response = [];
		$id = $request->getPost('id');

		if (
			!\CCalendar::IsPersonal()
			&& !SectionAccessController::can(\CCalendar::GetUserId(), ActionDictionary::ACTION_SECTION_EDIT, $id)
		)
		{
			$this->addError(new Error('[sd02]' . Loc::getMessage('EC_ACCESS_DENIED'), 'access_denied'));
		}

		$section = \CCalendarSect::GetById($id);
		// For exchange we change only calendar name
		if ($section && $section['CAL_DAV_CON'])
		{
			\CCalendarSect::Edit([
				'arFields' => [
					'ID' => $id,
					'ACTIVE' => 'N',
				],
			]);

			// Check if it's last section from connection - remove it
			$sections = \CCalendarSect::GetList([
				'arFilter' => [
					'CAL_DAV_CON' => $section['CAL_DAV_CON'],
					'ACTIVE' => 'Y',
				],
			]);

			if (empty($sections))
			{
				\CCalendar::setOwnerId(\CCalendar::GetUserId());
				\CCalendar::RemoveConnection(['id' => (int)$section['CAL_DAV_CON'], 'del_calendars' => true]);
			}
		}

		return $response;
	}

	public function getTrackingSectionsAction()
	{
		if (Loader::includeModule('intranet') && !\Bitrix\Intranet\Util::isIntranetUser())
		{
			return [];
		}

		$request = $this->getRequest();
		$mode = $request->get('type');

		$users = [];
		if ($mode === 'users')
		{
			$userIds = $request->get('userIdList');
			$ormRes = \Bitrix\Main\UserTable::getList([
				'filter' => ['=ID' => $userIds],
				'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
			]);
			while ($user = $ormRes->fetch())
			{
				$user['FORMATTED_NAME'] = \CCalendar::GetUserName($user);
				$users[] = $user;
			}

			$sections = \CCalendarSect::getSuperposedList(['USERS' => $userIds]);
		}
		elseif ($mode === 'groups' || $mode === 'collabs')
		{
			$sections = [];
			$groupIds = $request->get('groupIdList') ?? [];

			if (Loader::includeModule('socialnetwork'))
			{
				if (!empty($groupIds))
				{
					$sections = \CCalendarSect::getSuperposedList(['GROUPS' => $groupIds]);
				}

				foreach ($groupIds as $groupId)
				{
					$groupId = (int)$groupId;
					$createDefaultGroupSection = \CSocNetFeatures::isActiveFeature(
						SONET_ENTITY_GROUP,
						$groupId,
						"calendar"
					);

					if ($createDefaultGroupSection)
					{
						foreach ($sections as $section)
						{
							if ((int)$section['OWNER_ID'] === $groupId)
							{
								$createDefaultGroupSection = false;
								break;
							}
						}
					}

					if ($createDefaultGroupSection)
					{
						$sections[] = \CCalendarSect::createDefault([
							'type' => 'group',
							'ownerId' => $groupId,
						]);
					}
				}
			}
		}
		else
		{
			$types = [];
			$typesRes = \CCalendarType::GetList();
			foreach ($typesRes as $type)
			{
				if (
					$type['XML_ID'] !== 'user'
					&& $type['XML_ID'] !== 'group'
					&& $type['XML_ID'] !== 'location'
				)
				{
					$types[] = $type['XML_ID'];
				}
			}

			$sections = \CCalendarSect::getSuperposedList(['TYPES' => $types]);
		}

		return [
			'users' => $users,
			'sections' => $sections,
		];
	}

	public function setTrackingSectionsAction()
	{
		$request = $this->getRequest();
		$type = $request->get('type');

		$userId = \CCalendar::getCurUserId();
		if ($type === 'users')
		{
			UserSettings::setTrackingUsers($userId, $request->get('userIdList'));
		}

		$sections = $request->get('sections');
		if (!$sections)
		{
			$sections = [];
		}

		\CCalendar::setDisplayedSuperposed($userId, $sections);

		return [];
	}

	public function getEditEventSliderAction()
	{
		if (
			Loader::includeModule('intranet')
			&& !ToolsManager::getInstance()->checkAvailabilityByToolId('calendar')
		)
		{
			$this->addError(new Error('Tool not available'));

			return [
				'isAvailable' => false,
			];
		}
		$request = $this->getRequest();
		$responseParams = [];
		$uniqueId = 'calendar_edit_slider_' . mt_rand();
		$formType = preg_replace('/\W/', '', $request->get('form_type'));
		$entryId = (int)$request->get('event_id');
		$userCodes = $request->get('userCodes');
		$userId = \CCalendar::GetCurUserId();
		$ownerId = (int)$request->get('ownerId');
		$type = $request->get('type');
		$sections = [];

		if ($entryId > 0)
		{
			$timezoneOffsetRaw = $request->get('timezoneOffset');
			if ($timezoneOffsetRaw === null || $timezoneOffsetRaw === '')
			{
				$timezoneOffsetRaw = $request->get('date_from_offset');
			}
			$timezoneOffset = (int)$timezoneOffsetRaw;
			$entry = \CCalendarEvent::getEventForEditInterface($entryId, [
				'eventDate' => (string)$request->get('date_from'),
				'timezoneOffset' => $timezoneOffset,
				'userId' => $userId,
			]);
			$entryId = is_array($entry) && isset($entry['ID']) ? (int)$entry['ID'] : $entryId;

			if (!empty($entry) && is_array($entry))
			{
				$isCreator = (int)($entry['CREATED_BY'] ?? 0) === $userId;
				$isOwner = ($entry['CAL_TYPE'] ?? '') === 'user'
					&& (int)($entry['OWNER_ID'] ?? 0) === $userId;

				$isAttendee = false;

				if (!empty($entry['IS_MEETING']) && is_array($entry['ATTENDEE_LIST'] ?? null))
				{
					foreach ($entry['ATTENDEE_LIST'] as $attendee)
					{
						if ((int)$attendee['id'] === $userId)
						{
							$isAttendee = true;
							break;
						}
					}
				}

				if (!$isCreator && !$isOwner && !$isAttendee)
				{
					$accessController = new EventAccessController($userId);
					$eventModel = \CCalendarEvent::getEventModelForPermissionCheck(
						$entryId,
						$entry,
						$userId,
					);

					if (!$accessController->check(ActionDictionary::ACTION_EVENT_VIEW_FULL, $eventModel))
					{
						$this->addError(new Error('Access denied'));

						return [];
					}
				}
			}
		}
		else
		{
			$entry = [];
		}

		if (!$entryId || (!empty($entry) && \CCalendarSceleton::CheckBitrix24Limits(['id' => $uniqueId])))
		{
			$responseParams['uniqueId'] = $uniqueId;
			$responseParams['userId'] = $userId;
			$responseParams['editorId'] = $uniqueId . '_entry_slider_editor';
			$responseParams['entry'] = $entry;
			$responseParams['timezoneHint'] = !empty($entry) ? Util::getTimezoneHint($userId, $entry) : '';
			$responseParams['timezoneList'] = \CCalendar::GetTimezoneList();
			$responseParams['absenceAvailable'] = (!\CCalendar::IsBitrix24()
				|| \COption::GetOptionString("bitrix24",  "absence_limits_enabled", "") !== "Y"
				|| Feature::isFeatureEnabled("absence"))
			;
			$responseParams['formSettings'] = UserSettings::getFormSettings($formType);

			$isUserEvent = $type === Dictionary::CALENDAR_TYPE['user'];
			$isOpenEvent = $type === Dictionary::CALENDAR_TYPE['open_event'];
			$isGroupEvent = $type === Dictionary::CALENDAR_TYPE['group'];
			$isCollabUser = Util::isCollabUser($userId);

			if ($type)
			{
				if ($ownerId && $isGroupEvent)
				{
					$permission = \CCalendar::GetPermissions([
						'type' => $type,
						'ownerId' => $ownerId,
						'userId' => $userId,
					]);

					if (!$permission['edit'])
					{
						$this->addError(new Error('access denied'));

						return [];
					}
				}

				$sectionList = [];

				if ($isOpenEvent)
				{
					$sectionList = \CCalendar::getSectionList([
						'CAL_TYPE' => Dictionary::CALENDAR_TYPE['open_event'],
					]);
				}
				else if ($isCollabUser)
				{
					$userCollabIds = UserCollabs::getInstance()->getIds($userId);
					if (
						$type === Dictionary::CALENDAR_TYPE['group']
						&& !in_array($ownerId, $userCollabIds, true)
					)
					{
						$this->addError(new Error('access denied'));

						return [];
					}

					$sectionList = \CCalendar::getSectionList([
						'CAL_TYPE' => Dictionary::CALENDAR_TYPE['group'],
						'OWNER_ID' => $userCollabIds,
						'ACTIVE' => 'Y',
						'checkPermissions' => true,
						'getPermissions' => true,
					]);
				}
				else if (
					($isUserEvent && $ownerId !== $userId)
					|| !$isUserEvent
				)
				{
					$sectionList = \CCalendar::getSectionList([
						'CAL_TYPE' => $type,
						'OWNER_ID' => $ownerId,
						'ACTIVE' => 'Y',
						'checkPermissions' => true,
						'getPermissions' => true,
					]);
				}

				$hasCurrentCalendarInSections = false;
				foreach ($sectionList as $section)
				{
					if ($section['CAL_TYPE'] === Dictionary::CALENDAR_TYPE['open_event'])
					{
						$section['PERM']['edit'] = true;
					}

					if ($section['PERM']['edit'] || $section['PERM']['add'])
					{
						$sections[] = $section;

						if (
							$section['CAL_TYPE'] === Dictionary::CALENDAR_TYPE['group']
							&& (int)$section['OWNER_ID'] === $ownerId
						)
						{
							$hasCurrentCalendarInSections = true;
						}
					}
				}

				if (
					$isGroupEvent
					&& (empty($sections) || !$hasCurrentCalendarInSections)
				)
				{
					$sections[] = \CCalendarSect::createDefault([
						'type' => $type,
						'ownerId' => $ownerId,
					]);

					\CCalendarSect::setClearOperationCache();
				}
			}

			if (!$isOpenEvent && !$isCollabUser)
			{
				$sections = array_merge(
					$sections,
					\CCalendar::getSectionListAvailableForUser($userId, (array)($entry['SECTION_ID'] ?? null))
				);
			}

			$responseParams['sections'] = [];
			foreach ($sections as $section)
			{
				if (
					($section['PERM']['edit'] ?? false)
					&& !\CCalendarSect::CheckGoogleVirtualSection(
						$section['GAPI_CALENDAR_ID'] ?? null,
						$section['EXTERNAL_TYPE'] ?? null,
					)
				)
				{
					$responseParams['sections'][] = $section;
				}
			}

			if (
				empty($responseParams['sections'])
				&& $isUserEvent
				&& !empty($ownerId)
			)
			{
				$responseParams['sections'][] = \CCalendarSect::createDefault([
					'type' => $type,
					'ownerId' => $ownerId,
				]);

				\CCalendarSect::setClearOperationCache();
			}

			$responseParams['dayOfWeekMonthFormat'] = (
				\Bitrix\Main\Context::getCurrent()
					->getCulture()
					->getDayOfWeekMonthFormat()
			);
			$responseParams['trackingUsersList'] = UserSettings::getTrackingUsers($userId);
			$responseParams['userSettings'] = UserSettings::get($userId);
			$responseParams['iblockMeetingRoomList'] = Rooms\IBlockMeetingRoom::getMeetingRoomList();
			$responseParams['userIndex'] = \CCalendarEvent::getUserIndex();
			$locationFeatureEnabled = Bitrix24Manager::isFeatureEnabled(FeatureDictionary::CALENDAR_LOCATION);

			// collab user not have access to rooms management, but if it already set for event, it should display
			$responseParams['locationFeatureEnabled'] = !$isCollabUser && $locationFeatureEnabled;
			if ($locationFeatureEnabled || $isCollabUser)
			{
				$responseParams['locationList'] = Rooms\Manager::getRoomsList();
				$responseParams['locationAccess'] = Rooms\Util::getLocationAccess($userId);
			}
			$responseParams['plannerFeatureEnabled'] = Bitrix24Manager::isPlannerFeatureEnabled();
			$responseParams['attendeesEntityList'] = ($entryId > 0 && !empty($entry['attendeesEntityList']))
				? $entry['attendeesEntityList']
				: Util::getDefaultEntityList($userId, $type, $ownerId);
			$responseParams['meetSection'] = null;
			if ($type === Dictionary::CALENDAR_TYPE['user'])
			{
				$responseParams['meetSection'] = UserSettings::get($ownerId)['meetSection'] ?? null;
			}
			$responseParams['eventWithEmailGuestEnabled'] = Bitrix24Manager::isFeatureEnabled(FeatureDictionary::CALENDAR_EVENTS_WITH_EMAIL_GUESTS);

			if ($isOpenEvent)
			{
				$responseParams['defaultCategoryId'] = DefaultCategoryService::getInstance()->getCategoryId();
			}

			$responseParams['projectFeatureEnabled'] = SocialNetwork\FeatureService::isProjectFeatureEnabled();
			$responseParams['teamsAsAttendeeEnabled'] = HumanResources\FeatureService::isTeamsAsAttendeeEnabled();

			$responseParams['isCollabUser'] = $isCollabUser;

			return new \Bitrix\Main\Engine\Response\Component(
				'bitrix:calendar.edit.slider',
				'',
				[
					'id' => $uniqueId,
					'event' => $entry,
					'formType' => $formType,
					'type' => $isOpenEvent ? Dictionary::CALENDAR_TYPE['open_event'] : \CCalendar::GetType(),
					'bIntranet' => \CCalendar::IsIntranetEnabled(),
					'bSocNet' => \CCalendar::IsSocNet(),
					'AVATAR_SIZE' => 21,
					'ATTENDEES_CODES' => $userCodes,
					'hiddenFields' => $this->getEventEditFormHiddenFields($entry),
					'sections' => $responseParams['sections'],
				],
				$responseParams
			);
		}

		$this->addError(new Error('[se05] No entry found'));

		return [];
	}

	public function getStandaloneCompactFormDataAction(): array
	{
		if (
			Loader::includeModule('intranet')
			&& !ToolsManager::getInstance()->checkAvailabilityByToolId('calendar')
		)
		{
			$this->addError(new Error('Tool not available'));

			return [];
		}

		$type = Dictionary::CALENDAR_TYPE['user'];
		$userId = \CCalendar::GetCurUserId();

		$isCollabUser = Util::isCollabUser($userId);
		$sections = $this->getSectionsForStandaloneForm($userId, $isCollabUser);

		$responseParams = [];
		$responseParams['sections'] = $sections;
		$responseParams['userId'] = $userId;
		$responseParams['userSettings'] = UserSettings::get($userId);
		$responseParams['userIndex'] = \CCalendarEvent::getUserIndex();
		$responseParams['isCollabUser'] = $isCollabUser;
		$responseParams['hiddenSections'] = UserSettings::getHiddenSections($userId, [
			'isPersonalCalendarContext' => true,
			'type' => $type,
		]);
		$responseParams['trackingUsersList'] = UserSettings::getTrackingUsers($userId);

		$locationFeatureEnabled = Bitrix24Manager::isFeatureEnabled(FeatureDictionary::CALENDAR_LOCATION);
		$responseParams['locationFeatureEnabled'] = !$isCollabUser && $locationFeatureEnabled;
		if ($locationFeatureEnabled || $isCollabUser)
		{
			$responseParams['locationList'] = Rooms\Manager::getRoomsList();
			$responseParams['locationAccess'] = Rooms\Util::getLocationAccess($userId);
		}

		$responseParams['plannerFeatureEnabled'] = Bitrix24Manager::isPlannerFeatureEnabled();
		$responseParams['eventWithEmailGuestEnabled'] = Bitrix24Manager::isFeatureEnabled(FeatureDictionary::CALENDAR_EVENTS_WITH_EMAIL_GUESTS);
		$responseParams['isCollabFeatureEnabled'] = \Bitrix\Calendar\Integration\SocialNetwork\Collab\CollabFeature::isAvailable();
		$responseParams['projectFeatureEnabled'] = SocialNetwork\FeatureService::isProjectFeatureEnabled();
		$responseParams['isNewProjectsOn'] = SocialNetwork\FeatureService::isNewProjectsOn();
		$responseParams['teamsAsAttendeeEnabled'] = HumanResources\FeatureService::isTeamsAsAttendeeEnabled();

		return $responseParams;
	}

	/**
	 * The compact form is opened outside the calendar screen, so this action is the only place
	 * that can guarantee a container the event will be saved into.
	 *
	 * The lists of the two branches mean different things. The personal one is everything the user
	 * sees: the portal section of open events, followed calendars of colleagues and virtual Google
	 * sections come with it, and not all of them take an event. The collab one carries sections with
	 * the write right only. Picking a section by that right is left to the client.
	 */
	private function getSectionsForStandaloneForm(int $userId, bool $isCollabUser): array
	{
		return $isCollabUser
			? $this->getCollabSectionsForStandaloneForm($userId)
			: $this->getPersonalSectionsForStandaloneForm($userId)
		;
	}

	private function getPersonalSectionsForStandaloneForm(int $userId): array
	{
		$type = Dictionary::CALENDAR_TYPE['user'];

		// user calendars are denied to an external user by the same policy that builds the list
		// below, so a container created for them would never be offered
		if (Loader::includeModule('extranet') && !\CExtranet::IsIntranetUser(SITE_ID, $userId))
		{
			return [];
		}

		// the section list is wider than the personal container (open events add the portal
		// section to it), so the container is probed by the method that also creates it
		$container = \CCalendarSect::GetSectionForOwner($type, $userId, true);
		if (empty($container['sectionId']))
		{
			// the guarantee is the whole point of this action, so its refusal must not reach the client
			// as the empty list an external user or a collaber without a single collab legally gets
			$this->addError(new Error('[sc01] Personal calendar section is unavailable', 'section_guarantee_failed'));

			return [];
		}

		if ($container['autoCreated'])
		{
			\CCalendarSect::SetClearOperationCache(true);
		}

		return \CCalendarSect::prepareSectionListResponse($type, (string)$userId);
	}

	// a personal section is rejected on save for a collab user, so only collab sections are offered
	private function getCollabSectionsForStandaloneForm(int $userId): array
	{
		$collabIds = UserCollabs::getInstance()->getIds($userId);
		if (empty($collabIds))
		{
			return [];
		}

		// section access rules ask for the group role of an external user one section at a time;
		// the batch form fills the same static role cache in a single query
		\CSocNetUserToGroup::GetUserRole($userId, $collabIds);

		$sectionList = \CCalendar::getSectionList([
			'CAL_TYPE' => Dictionary::CALENDAR_TYPE['group'],
			'OWNER_ID' => $collabIds,
			'ACTIVE' => 'Y',
			'checkPermissions' => true,
			'getPermissions' => true,
		]);

		$sections = [];
		foreach ($sectionList as $section)
		{
			if (
				($section['PERM']['edit'] ?? false)
				&& !\CCalendarSect::CheckGoogleVirtualSection(
					$section['GAPI_CALENDAR_ID'] ?? null,
					$section['EXTERNAL_TYPE'] ?? null,
				)
			)
			{
				$sections[] = $section;
			}
		}

		return $sections;
	}

	private function getEventEditFormHiddenFields(array $entry): array
	{
		$hiddenFields = [];

		if ($this->isSharingEvent($entry))
		{
			$hiddenFields = array_merge(
				$hiddenFields,
				[
					self::EVENT_EDIT_FORM_FIELDS_THAT_CAN_BE_HIDDEN['crm'],
				]
			);
		}

		return $hiddenFields;
	}

	private function isSharingEvent(array $entry): bool
	{
		return
			isset($entry['EVENT_TYPE'])
			&& in_array($entry['EVENT_TYPE'], Sharing\SharingEventManager::getSharingEventTypes())
		;
	}

	public function getViewEventSliderAction()
	{
		$request = $this->getRequest();
		$responseParams = [];
		$uniqueId = 'calendar_view_slider_' . mt_rand();
		$entryId = (int)$request->get('entryId');
		$userId = \CCalendar::GetCurUserId();
		$entry = null;

		if ($entryId)
		{
			$entry = \CCalendarEvent::getEventForViewInterface($entryId,
				[
					'eventDate' => $request->get('dateFrom'),
					'timezoneOffset' => (int)$request->get('timezoneOffset'),
					'userId' => $userId,
				]
			);
		}
		else
		{
			$this->addError(new Error(Loc::getMessage('EC_EVENT_NOT_FOUND'), 'EVENT_NOT_FOUND_01'));
		}

		if ($entry)
		{
			$responseParams['uniqueId'] = $uniqueId;
			$responseParams['userId'] = $userId;
			$responseParams['userTimezone'] = \CCalendar::GetUserTimezoneName($userId);
			$responseParams['entry'] = $entry;
			$responseParams['userIndex'] = \CCalendarEvent::getUserIndex();
			$responseParams['userSettings'] = UserSettings::get($userId);
			$responseParams['plannerFeatureEnabled'] = Bitrix24Manager::isPlannerFeatureEnabled();
			$responseParams['entryUrl'] = \CCalendar::getEntryUrl(
				$entry['CAL_TYPE'],
				$entry['OWNER_ID'],
				$entry['ID'],
				$entry['DATE_FROM']
			);
			$responseParams['dayOfWeekMonthFormat'] = (
				\Bitrix\Main\Context::getCurrent()
					->getCulture()
					->getDayOfWeekMonthFormat()
			);

			$sections = \CCalendarSect::GetList([
				'arFilter' => [
					'ID' => $entry['SECTION_ID'],
					'ACTIVE' => 'Y',
				],
				'checkPermissions' => false,
				'getPermissions' => true,
			]);

			$responseParams['section'] = isset($sections[0]) ? $sections[0] : null;

			if (!$responseParams['section'])
			{
				$this->addError(new Error(Loc::getMessage('EC_EVENT_NOT_FOUND'), 'SECTION_NOT_FOUND'));

				return [];
			}

			$permissions = \CCalendarEvent::getEventPermissions($entry, $userId);
			$entry['permissions'] = $permissions;
			$viewData = EventViewData::prepare(
				$entry,
				$responseParams['section'],
				$permissions,
				$responseParams['userIndex'],
				$userId,
			);
			$entry = $viewData['entry'];
			$responseParams['entry'] = $entry;
			$responseParams['section'] = $viewData['section'];
			$responseParams['userIndex'] = $viewData['userIndex'];
			if ($viewData['restricted'])
			{
				$responseParams['entryUrl'] = '';
			}

			return new \Bitrix\Main\Engine\Response\Component(
				'bitrix:calendar.view.slider',
				'',
				[
					'id' => $uniqueId,
					'event' => $entry,
					'type' => \CCalendar::GetType(),
					'sectionName' => $viewData['restricted'] ? '' : $_REQUEST['section_name'],
					'isRestrictedEventView' => $viewData['restricted'],
					'userIndex' => $viewData['userIndex'],
					'bIntranet' => \CCalendar::IsIntranetEnabled(),
					'bSocNet' => \CCalendar::IsSocNet(),
					'AVATAR_SIZE' => 21,
				],
				$responseParams
			);
		}

		$this->addError(new Error(Loc::getMessage('EC_EVENT_NOT_FOUND'), 'EVENT_NOT_FOUND_02'));

		return [];
	}

	public function getCrmUserfieldAction()
	{
		$request = $this->getRequest();
		$UF = \CCalendarEvent::GetEventUserFields(['PARENT_ID' => (int)$request->get('event_id')]);
		if (isset($UF['UF_CRM_CAL_EVENT']))
		{
			$crmUF = $UF['UF_CRM_CAL_EVENT'];
			$additionalResponseParams = [];
			return new \Bitrix\Main\Engine\Response\Component(
				'bitrix:system.field.edit',
				$crmUF["USER_TYPE"]["USER_TYPE_ID"],
				[
					"bVarsFromForm" => false,
					"arUserField" => $crmUF,
					"form_name" => 'event_edit_form',
				],
				$additionalResponseParams
			);
		}

		return [];
	}

	public function updatePlannerAction()
	{
		$request = $this->getRequest();
		$entryId = (int)$request['entryId'];
		$parentId = (int)($request['entry']['parentId'] ?? $request['parentId'] ?? 0);
		$userId = \CCalendar::getCurUserId();
		$type = $request['type'];
		$entries = $request['entries'];
		$isExtranetUser = Loader::includeModule('intranet') && !\Bitrix\Intranet\Util::isIntranetUser($userId);

		if (!$entryId && $request['cur_event_id'])
		{
			$entryId = (int)$request['cur_event_id'];
		}

		if ($entryId > 0)
		{
			$event = \CCalendarEvent::GetById($entryId);
			$hostId = $event ? (int)($event['MEETING_HOST'] ?? 0) : 0;
			// The rights cost two reads and a batch check, so they are asked for the viewers alone
			// who may not be told the host by their own relation to the event.
			if ($hostId > 0 && $hostId !== $userId && !EventViewData::isOwnEntry($event, $userId))
			{
				$canReadFullEntry = EventViewData::canReadFullEntry(
					$event,
					\CCalendarEvent::getEventPermissions($event, $userId),
					$userId,
				);
				$hostId = $canReadFullEntry ? $hostId : 0;
			}
		}
		else
		{
			$hostId = (int)$request['hostId'];
			if (!$hostId && $type !== Dictionary::CALENDAR_TYPE['open_event'])
			{
				$hostId = $userId;
			}
		}

		if ($isExtranetUser)
		{
			$entries = \CExtranet::getMyGroupsUsersSimple(\CExtranet::GetExtranetSiteID());
		}

		$codes = [];
		$attendeesProvided = ($request['attendeesProvided'] ?? null) === 'Y'
			|| is_array($request['entityList'])
			|| is_array($request['codes']);
		// open event need only location planner, so ignore entityList
		if ($type !== Dictionary::CALENDAR_TYPE['open_event'])
		{
			if (isset($request['entityList']) && is_array($request['entityList']))
			{
				$codes = Util::convertEntitiesToCodes($request['entityList']);
			}
			elseif (isset($request['codes']) && is_array($request['codes']))
			{
				$codes = $request['codes'];
			}

			if (!$attendeesProvided)
			{
				if ($hostId > 0)
				{
					$codes[] = 'U' . $hostId;
				}
				if ($entryId <= 0 && $userId !== $hostId)
				{
					$codes[] = 'U' . $userId;
				}
			}
		}

		$entryType = $request['entry']['data']['CAL_TYPE'] ?? null;
		if ($entryType === Dictionary::CALENDAR_TYPE['open_event'])
		{
			$codes = ['U' . $userId];
		}

		$prevUserList = is_array($request['prevUserList']) ? $request['prevUserList'] : [];
		$skipFeatureCheck = ($request['skipFeatureCheck'] ?? null) === 'Y';

		$dateFrom = $request['dateFrom'] ?? $request['date_from'];
		$dateTo = $request['dateTo'] ?? $request['date_to'];

		return \CCalendarPlanner::prepareData([
			'parent_id' => $parentId,
			'entry_id' => $entryId,
			'user_id' => $userId,
			'host_id' => $hostId,
			'codes' => $codes,
			'entryLocation' => trim($request['entryLocation'] ?? ""),
			'entries' => $entries,
			'date_from' => $dateFrom,
			'date_to' => $dateTo,
			'timezone' => $request['timezone'],
			'location' => trim($request['location'] ?? ""),
			'roomEventId' => (int)$request['roomEventId'],
			'initPullWatches' => true,
			'prevUserList' => $prevUserList,
			'skipFeatureCheck' => $skipFeatureCheck,
		]);
	}

	public function getPlannerAction()
	{
		$request = $this->getRequest();
		\CCalendarPlanner::Init(['id' => $request['planner_id']]);
		return [];
	}

	public function deleteCalendarEntryAction($entryId, $recursionMode, $requestUid)
	{
		$response = [];

		$response['result'] = \CCalendar::deleteEvent(
			$entryId,
			true,
			[
				'recursionMode' => $recursionMode,
				'requestUid' => (int)$requestUid,
			]
		);

		if ($response['result'] !== true)
		{
			$this->addError(new Error('[ed01]' . Loc::getMessage('EC_EVENT_DEL_ERROR'), 'delete_entry_error'));
		}

		return $response;
	}

	public function changeRecurciveEntryUntilAction($entryId, $untilDate)
	{
		$response = ['result' => false];

		$event = \CCalendarEvent::GetById((int)$entryId);
		$untilTimestamp = \CCalendar::Timestamp($untilDate);
		$recId = false;

		if ($event)
		{
			if (
				\CCalendarEvent::CheckRecurcion($event)
				&& $this->doesUntilDateShortenRecurrence($event, $untilTimestamp)
			)
			{
				$event['RRULE'] = \CCalendarEvent::ParseRRULE($event['RRULE']);
				$event['RRULE']['UNTIL'] = \CCalendar::Date($untilTimestamp, false);
				if (isset($event['RRULE']['COUNT']))
				{
					unset($event['RRULE']['COUNT']);
				}

				$id = \CCalendar::SaveEvent([
					'arFields' => [
						"ID" => $event["ID"],
						"RRULE" => $event['RRULE'],
					],
					'silentErrorMode' => false,
					'recursionEditMode' => 'skip',
					'editParentEvents' => true,
					'editEntryUntil' => true,
					'checkLocationOccupancy' => 'N',
				]);
				if ($id === false)
				{
					$this->addError(new Error(
						'[ed01]' . Loc::getMessage('EC_EVENT_DEL_ERROR'),
						'change_recurcive_entry_until',
					));

					return ['result' => false];
				}
				$recId = $event["ID"];
				$response['id'] = $id;
			}

			if ($event["RECURRENCE_ID"] > 0)
			{
				$recParentEvent = \CCalendarEvent::GetById($event["RECURRENCE_ID"]);
				if ($recParentEvent && \CCalendarEvent::CheckRecurcion($recParentEvent))
				{
					$recParentEvent['RRULE'] = \CCalendarEvent::ParseRRULE($recParentEvent['RRULE']);

					if ($this->doesUntilDateShortenRecurrence($recParentEvent, $untilTimestamp))
					{
						$recParentEvent['RRULE']['UNTIL'] = \CCalendar::Date($untilTimestamp, false);

						if (isset($recParentEvent['RRULE']['COUNT']))
						{
							unset($recParentEvent['RRULE']['COUNT']);
						}

						$id = \CCalendar::SaveEvent([
							'arFields' => [
								"ID" => $recParentEvent["ID"],
								"RRULE" => $recParentEvent['RRULE'],
							],
							'silentErrorMode' => false,
							'recursionEditMode' => 'skip',
							'editParentEvents' => true,
							'editEntryUntil' => true,
							'checkLocationOccupancy' => 'N',
						]);
						if ($id === false)
						{
							$this->addError(new Error(
								'[ed01]' . Loc::getMessage('EC_EVENT_DEL_ERROR'),
								'change_recurcive_entry_until',
							));

							return ['result' => false];
						}
						$response['id'] = $id;
						$recId = $event["RECURRENCE_ID"];
					}
				}
			}

			if ($recId)
			{
				$recRelatedEvents = \CCalendarEvent::GetEventsByRecId($recId, false);
				foreach ($recRelatedEvents as $ev)
				{
					if (\CCalendar::Timestamp($ev['DATE_FROM']) > $untilTimestamp)
					{
						\CCalendar::DeleteEvent((int)$ev['ID'], true, ['recursionMode' => 'this']);
					}
				}
			}

			$response['result'] = true;
		}

		if ($response['result'] !== true)
		{
			$this->addError(new Error('[ed01]' . Loc::getMessage('EC_EVENT_DEL_ERROR'),
				'change_recurcive_entry_until'));
		}

		return $response;
	}

	private function doesUntilDateShortenRecurrence(array $event, int $untilTimestamp): bool
	{
		$rrule = \CCalendarEvent::ParseRRULE($event['RRULE'] ?? null);
		if (isset($rrule['COUNT']))
		{
			$event['RRULE'] = $rrule;
			$event['EXDATE'] = '';
			$instances = [];
			\CCalendarEvent::ParseRecursion($instances, $event, [
				'userId' => \CCalendar::GetCurUserId(),
				'fromLimitTs' => $untilTimestamp - \CCalendar::GetDayLen(),
				'toLimitTs' => \CCalendarEvent::calculateUntilForCountRRule($event),
				'loadLimit' => null,
				'instanceCount' => false,
				'preciseLimits' => true,
			]);

			foreach ($instances as $instance)
			{
				if (\CCalendar::Timestamp($instance['DATE_FROM']) > $untilTimestamp)
				{
					return true;
				}
			}

			return false;
		}

		if (!empty($rrule['UNTIL']))
		{
			return \CCalendar::Timestamp($rrule['UNTIL']) > $untilTimestamp;
		}

		return true;
	}

	public function excludeRecursionDateAction($entryId, $excludeDate)
	{
		return $this->executeExcludedInstanceAction((int)$entryId, $excludeDate);
	}

	private function executeExcludedInstanceAction(int $entryId, mixed $excludeDate): array
	{
		$this->addErrors(
			\Bitrix\Calendar\Internal\Event\ExcludedInstanceOperation::execute($entryId, $excludeDate)->getErrors(),
		);

		return [];
	}

	public function deleteCalendarSectionAction($id)
	{
		$response = [];

		$sectionList = SectionTable::getList([
				'filter' => [
					'=ACTIVE' => 'Y',
					'=ID' => (int)$id,
				],
				'limit' => 1,
			]
		);

		if (!($section = $sectionList->fetch()))
		{
			$this->addError(new Error(Loc::getMessage('EC_SECTION_NOT_FOUND'), 'section_not_found'));

			return $response;
		}

		$accessController = new SectionAccessController(\CCalendar::GetUserId());
		$sectionModel = SectionModel::createFromArray($section);

		if (!$accessController->check(ActionDictionary::ACTION_SECTION_EDIT, $sectionModel))
		{
			$this->addError(new Error(Loc::getMessage('EC_ACCESS_DENIED'), 'access_denied'));

			return $response;
		}

		\CCalendar::DeleteSection($id);

		return $response;
	}

	public function setMeetingStatusAction()
	{
		$userId = \CCalendar::GetCurUserId();
		$request = $this->getRequest();
		$response = [];

		$entryId = (int)$request->getPost('entryId');
		/** @var Factory $mapperFactory */
		$mapperFactory = ServiceLocator::getInstance()->get('calendar.service.mappers.factory');
		/** @var Event $event */
		$event = $mapperFactory->getEvent()->getById($entryId);
		if ($event->isOpenEvent())
		{
			OpenEventAttendeeService::getInstance()->setEventAttendeeStatus(
				$userId,
				new SetEventAttendeeStatusDto(
					$event->getId(),
					$request->getPost('status') === 'Y'
				)
			);
		}
		else
		{
			$meetingStatus = $request->getPost('status');
			$meetingStatusResult = \CCalendarEvent::SetMeetingStatusEx([
				'attendeeId' => $userId,
				'eventId' => (int)$request->getPost('entryId'),
				'parentId' => (int)$request->getPost('entryParentId'),
				'status' => $meetingStatus,
				'reccurentMode' => $request->getPost('recursionMode'),
				'currentDateFrom' => $request->getPost('currentDateFrom'),
			]);

			if ($meetingStatusResult === false)
			{
				$this->addError(new Error(
					'Meeting status was not applied.',
					'MEETING_STATUS_NOT_APPLIED',
				));

				return null;
			}

			$section = $event->getSection();
			$isGroupSection = $section?->getType() === Dictionary::CALENDAR_TYPE['group'];
			$groupId = $isGroupSection ? $section?->getOwnerId() : null;

			if ($groupId)
			{
				$userType = match (true)
				{
					Util::isCollabUser($userId) => Analytics::USER_TYPES['collaber'],
					Util::isExtranetUser($userId) => Analytics::USER_TYPES['extranet'],
					default => Analytics::USER_TYPES['intranet'],
				};

				if ($meetingStatus === 'Y')
				{
					Analytics::getInstance()->onEventAccept(
						eventId: $entryId,
						userId: $userId,
						groupId: $groupId,
						subSection: Analytics::SUB_SECTION['calendar'],
						userType: $userType,
						status: AnalyticsEvent::STATUS_SUCCESS,
					);
				}
				elseif ($meetingStatus === 'N')
				{
					Analytics::getInstance()->onEventDecline(
						eventId: $entryId,
						userId: $userId,
						groupId: $groupId,
						subSection: Analytics::SUB_SECTION['calendar'],
						userType: $userType,
						status: AnalyticsEvent::STATUS_SUCCESS,
					);
				}
			}
		}

		\CCalendar::UpdateCounter([$userId], [$event->getId()]);
		$response['counters'] = CountersManager::getValues($userId);

		return $response;
	}

	public function updateRemindersAction()
	{
		$request = $this->getRequest();
		$response = [];
		$entryId = (int)$request->getPost('entryId');
		$userId = \CCalendar::GetUserId();
		$entry = \CCalendarEvent::GetById($entryId);

		if (empty($entry))
		{
			$this->addError(new Error('Event not found'));

			return $response;
		}

		$accessController = new EventAccessController($userId);
		$eventModel = \CCalendarEvent::getEventModelForPermissionCheck($entryId, $entry, $userId);

		if ($accessController->check(ActionDictionary::ACTION_EVENT_EDIT, $eventModel, ['checkCurrentEvent' => 'Y']))
		{
			$entry['REMIND'] = \CCalendarReminder::prepareReminder($request->getPost('reminders'));
			try
			{
				$response['id'] = \CCalendar::SaveEvent([
					'arFields' => [
						'ID' => $entry['ID'],
						'REMIND' => $entry['REMIND'],
					],
					'updateReminders' => true,
					'checkPermission' => false,
				]);
			}
			catch (Rooms\OccupancyLockedException)
			{
				$this->addError(
					new Error(
						Loc::getMessage('EC_LOCATION_BUSY') ?: 'Meeting room occupancy is locked.',
						Rooms\OccupancyLockedException::ERROR_CODE,
					),
				);

				return [];
			}

			$response['REMIND'] = $entry['REMIND'];

			\CCalendar::ClearCache('event_list');
		}
		else
		{
			$this->addError(new Error('Access denied'));
		}

		return $response;
	}

	public function getSyncInfoAction()
	{
		$params = [];
		$request = $this->getRequest();
		$params['type'] = $request->getPost('type');
		$params['userId'] = \CCalendar::getCurUserId();

		return \CCalendarSync::GetSyncInfo($params);
	}

	public function setSectionStatusAction()
	{
		$attestedSectionsStatus = [];
		$request = $this->getRequest();
		$sectionsStatus = $request['sectionStatus'];
		$userId = \CCalendar::getCurUserId();

		foreach ($sectionsStatus as $sectionId => $sectionStatus)
		{
			$sectionStatus = json_decode($sectionStatus);
			if (is_int($sectionId) && is_bool($sectionStatus))
			{
				$attestedSectionsStatus[$sectionId] = $sectionStatus;
			}
		}

		if ($attestedSectionsStatus && $userId > 0)
		{
			\CCalendarSync::SetSectionStatus($userId, $attestedSectionsStatus);
			return true;
		}

		return false;
	}

	public function sendAnalyticsLabelAction()
	{
		return null;
	}

	public function updateColorAction()
	{
		$request = $this->getRequest();
		$response = [];
		$entryId = intVal($request->getPost('entryId'));
		$userId = \CCalendar::GetUserId();
		$entry = \CCalendarEvent::GetById($entryId);

		if (empty($entry))
		{
			$this->addError(new Error('Event not found'));

			return $response;
		}

		$accessController = new EventAccessController($userId);
		$eventModel = \CCalendarEvent::getEventModelForPermissionCheck($entryId, $entry, $userId);

		if ($accessController->check(ActionDictionary::ACTION_EVENT_EDIT, $eventModel, ['checkCurrentEvent' => 'Y']))
		{
			\CCalendarEvent::updateColor($entryId, $request->getPost('color'));
			\CCalendar::ClearCache('event_list');
		}
		else
		{
			$this->addError(new Error('Access denied'));
		}

		return $response;
	}

	public function getSettingsSliderAction($uid, $showPersonalSettings, $showGeneralSettings, $showAccessControl)
	{
		$uid = preg_replace('/\W/', '', $uid);

		$userId = \CCalendar::getCurUserId();
		$isCollabUser = Util::isCollabUser($userId);
		$canShowAccessControl = !$isCollabUser && (
			$showAccessControl === 'Y'
			|| $showGeneralSettings === 'Y'
		);

		$additionalResponseParams = [
			'uid' => $uid,
			'mailboxList' => \Bitrix\Calendar\Integration\Sender\AllowedSender::getList($userId),
		];

		$listLockedFeatures = [];

		if (\Bitrix\Main\Loader::includeModule('tasks'))
		{
			$featureName = \Bitrix\Tasks\Integration\Bitrix24\FeatureDictionary::TASK_CALENDAR_INTEGRATION;
			$tasksCalendarIntegration = \Bitrix\Tasks\Integration\Bitrix24::checkFeatureEnabled($featureName);

			if ($tasksCalendarIntegration === false)
			{
				$listLockedFeatures['sync_tasks'] = [
					'locked' => true,
					'code' => $featureName,
				];
			}
		}

		return new \Bitrix\Main\Engine\Response\Component(
			'bitrix:calendar.settings.slider',
			'',
			[
				'id' => $uid,
				'is_personal' => $showPersonalSettings === 'Y',
				'show_general_settings' => $showGeneralSettings === 'Y',
				'show_access_control' => $canShowAccessControl,
				'list_locked_features' => $listLockedFeatures,
			],
			$additionalResponseParams
		);
	}

	public function getAllowedMailboxDataAction()
	{
		$userId = \CCalendar::getCurUserId();
		return new \Bitrix\Main\Engine\Response\Component(
			'bitrix:main.mail.confirm',
			'',
			[],
			[
				'mailboxList' => \Bitrix\Calendar\Integration\Sender\AllowedSender::getList($userId),
			]
		);
	}

	public function getAllowedMailboxListAction()
	{
		$userId = \CCalendar::getCurUserId();
		return [
			'mailboxList' => \Bitrix\Calendar\Integration\Sender\AllowedSender::getList($userId),
		];
	}

	public function getCompactFormDataAction()
	{
		$request = $this->getRequest();
		$loadSectionId = (int)$request['loadSectionId'];
		$result = [];
		if ($loadSectionId > 0)
		{
			$result['section'] = \CCalendarSect::GetById($loadSectionId);
		}

		return $result;
	}

	public function getSectionListAction($type, $ownerId): array
	{
		$sections = \CCalendarSect::prepareSectionListResponse($type, (int)$ownerId);

		return [
			'sections' => $sections,
			'accessNames' => \CCalendar::GetAccessNames(),
		];
	}

	public function updateCountersAction(?string $type, ?string $ownerId): array
	{
		$userId = \CCalendar::GetCurUserId();
		\CCalendar::UpdateCounter([$userId]);

		$groupIds = $type === Dictionary::CALENDAR_TYPE['group'] ? [$ownerId] : [];

		return [
			'counters' => CountersManager::getValues($userId, $groupIds),
		];
	}

	public function updateDefaultSectionIdAction(string $key, int $sectionId): void
	{
		$userId = \CCalendar::GetCurUserId();
		$key = preg_replace("/[^a-zA-Z0-9_:\.]/is", "", $key);
		if ($key && $sectionId)
		{
			$userSettings = UserSettings::get($userId);
			$userSettings['defaultSections'][$key] = $sectionId;
			UserSettings::set($userSettings, $userId);
		}
	}

	public function analyticalAction(): void
	{
	}

	public function saveSettingsAction(
		string $type,
		array $user_settings = [],
		array $settings = []
	): void
	{
		$request = $this->getRequest();
		$userId = \CCalendar::GetCurUserId();

		// Personal
		UserSettings::set($user_settings);

		// Save access for type
		$accessController = new TypeAccessController($userId);
		$typeModel = TypeModel::createFromXmlId($type);

		if ($accessController->check(ActionDictionary::ACTION_TYPE_ACCESS, $typeModel))
		{
			// General
			if (!empty($settings))
			{
				\CCalendar::SetSettings($settings);
			}

			if (!empty($request['type_access']))
			{
				\CCalendarType::Edit([
					'arFields' => [
						'XML_ID' => $type,
						'ACCESS' => $request['type_access'],
					],
				]);
			}
		}
	}

	public function getFilterDataAction()
	{
		$request = $this->getRequest();
		$type = $request->getPost('type');

		$isCollaber = Util::isCollabUser(\CCalendar::getUserId());

		if ((Loader::includeModule('intranet') && !\Bitrix\Intranet\Util::isIntranetUser()) && !$isCollaber)
		{
			$this->addError(new Error('Intranet user only'));

			return [];
		}

		if ($isCollaber && !in_array($type, ['user', 'group'], true))
		{
			$this->addError(new Error('Collaber only can access user or group'));

			return [];
		}

		if ($type === 'user')
		{
			$params = [
				'ownerId' => \CCalendar::GetCurUserId(),
				'userId' => \CCalendar::GetCurUserId(),
				'type' => $type,
			];
		}
		else if (in_array($type, ['company_calendar', 'calendar_company', 'company', 'group'], true))
		{
			$accessController = new TypeAccessController(\CCalendar::GetCurUserId());
			$typeModel = TypeModel::createFromXmlId($type);

			if (!$accessController->check(ActionDictionary::ACTION_TYPE_VIEW, $typeModel))
			{
				$this->addError(new Error('Type access denied'));

				return [];
			}

			$params = [
				'ownerId' => $request->getPost('ownerId'),
				'userId' => \CCalendar::GetCurUserId(),
				'type' => $type,
			];
		}
		else
		{
			$this->addError(new Error('Type not found'));

			return [];
		}

		return CalendarFilter::getFilterData($params);
	}

	public function getConferenceChatIdAction(int $eventId)
	{
		$result = [];

		if (Loader::includeModule('intranet') && !\Bitrix\Intranet\Util::isIntranetUser())
		{
			return $result;
		}

		/** @var Sharing\Link\EventLink $eventLink */
		$eventLink = (new Sharing\Link\Factory())->getEventLinkByEventId($eventId);
		if (!$eventLink)
		{
			$this->addError(new Error('Event not found'));

			return $result;
		}

		$chatId = (new Sharing\SharingConference($eventLink))->getConferenceChatId();

		if (!$chatId)
		{
			$this->addError(new Error('Conference not found'));

			return $result;
		}

		$result['chatId'] = $chatId;

		return $result;
	}
}
