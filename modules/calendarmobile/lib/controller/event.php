<?php

namespace Bitrix\CalendarMobile\Controller;

use Bitrix\Calendar\Controller\CalendarEntryAjax;
use Bitrix\Calendar\Controller\Filter\RestrictExternalUser;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver;
use Bitrix\Calendar\Ui\CalendarFilter;
use Bitrix\Calendar\Ui\CountersManager;
use Bitrix\Calendar\UserSettings;
use Bitrix\CalendarMobile\AhaMoments\Factory;
use Bitrix\CalendarMobile\Integration;
use Bitrix\CalendarMobile\Provider\BaseInfoProvider;
use Bitrix\CalendarMobile\Provider\EditFormProvider;
use Bitrix\CalendarMobile\Provider\ViewFormProvider;
use Bitrix\Forum\Comments\CalendarEntity;
use Bitrix\Forum\MessageTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Mobile\Dto\InvalidDtoException;

class Event extends Controller
{
	private int $userId;

	protected function init(): void
	{
		parent::init();

		$this->userId = \CCalendar::GetUserId();
	}

	public function configureActions(): array
	{
		return [
			'loadMain' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getList' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getSectionList' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getViewFormConfig' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getEventByMessageId' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new CloseSession(),
				],
			],
			'getEditFormConfig' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getIcsLink' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getEventGuestLink' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
					new CloseSession(),
					new RestrictExternalUser(),
				],
			],
		];
	}

	/**
	 * @param int|null $ownerId
	 * @param string|null $calType
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws InvalidDtoException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function loadMainAction(?int $ownerId = null, ?string $calType = null): array
	{
		if ($ownerId === null || $calType === null)
		{
			$ownerId = $this->userId;
			$calType = Dictionary::CALENDAR_TYPE['user'];
		}

		$baseInfoProvider = new BaseInfoProvider($this->userId, $ownerId, $calType);

		$result = $baseInfoProvider->getBaseInfoConfig();
		if (!$result->isSuccess())
		{
			$this->addError($result->getError());
		}

		return $result->getData();
	}

	/**
	 * @return array[]
	 */
	public function getListAction(): array
	{
		$request = $this->getRequest();
		$yearFrom = (int)$request->getPost('yearFrom');
		$yearTo = (int)$request->getPost('yearTo');
		$monthFrom = (int)$request->getPost('monthFrom');
		$monthTo = (int)$request->getPost('monthTo');
		$limits = \CCalendarEvent::getLimitDates($yearFrom, $monthFrom, $yearTo, $monthTo);

		$sectionIdList = is_array($request->getPost('sectionIdList'))
			? array_map(static fn($section) => (int)$section, $request->getPost('sectionIdList'))
			: []
		;

		$events = [];
		if (!empty($sectionIdList))
		{
			$events = $this->getEvents($sectionIdList, $limits);
		}

		return [
			'events' => $events,
		];
	}

	/**
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getFilteredListAction(): array
	{
		$request = $this->getRequest();
		$search = (string)$request->getPost('search');
		$preset = $request->getPost('preset');
		$ownerId = (int)$request->getPost('ownerId');
		$calType = (string)$request->getPost('calType');

		if (empty($ownerId) || empty($calType))
		{
			$ownerId = $this->userId;
			$calType = Dictionary::CALENDAR_TYPE['user'];
		}

		$presetId = null;
		if (!empty($preset) && is_array($preset))
		{
			$presetId = $preset['id'] ?? null;
		}

		$sectionIds = CalendarFilter::getSectionsForFilter($calType, $presetId, $ownerId, $this->userId);

		$fields = [
			'SECTION_ID' => $sectionIds,
		];

		if (!empty($preset['fields']))
		{
			$fields = array_merge($fields, $preset['fields']);
		}

		$searchFields = [
			'search' => $search,
			'presetId' => $presetId,
			'fields' => $fields,
		];

		if ($calType === Dictionary::CALENDAR_TYPE['group'])
		{
			$searchResult = CalendarFilter::getFilterCompanyData(
				$calType,
				$this->userId,
				$ownerId,
				$searchFields
			);
		}
		else
		{
			$searchResult = CalendarFilter::getFilterUserData(
				$calType,
				$this->userId,
				$ownerId,
				$searchFields
			);
		}

		return [
			'events' => $searchResult['entries'],
		];
	}

	/**
	 * @param int $parentId
	 * @param int $ownerId
	 * @param string $calType
	 *
	 * @return array
	 */
	public function getEventForContextAction(int $parentId, int $ownerId, string $calType): array
	{
		 return \CCalendarEvent::GetList([
			'arFilter' => [
				'PARENT_ID' => $parentId,
				'OWNER_ID' => $ownerId,
				'CAL_TYPE' => $calType,
			],
			'parseRecursion' => false,
			'fetchAttendees' => true,
			'userId' => $this->userId,
			'setDefaultLimit' => false,
		]);
	}

	public function setAhaViewedAction(string $name): void
	{
		Factory::getInstance()->getAhaInstance($name)?->setViewed();
	}

	/**
	 * @param int|null $ownerId
	 * @param string|null $calType
	 *
	 * @return array[]
	 * @throws LoaderException
	 */
	public function getSectionListAction(?int $ownerId = null, ?string $calType = null): array
	{
		if ($ownerId === null || $calType === null)
		{
			$ownerId = $this->userId;
			$calType = Dictionary::CALENDAR_TYPE['user'];
		}

		$baseInfoProvider = new BaseInfoProvider($this->userId, $ownerId, $calType);

		if (!$baseInfoProvider->checkPermissions())
		{
			$this->addError(new Error('Access denied'));

			return [
				'sections' => [],
			];
		}

		return [
			'sections' => $baseInfoProvider->getSectionInfo(),
		];
	}

	/**
	 * @return array
	 */
	public function setMeetingStatusAction(): array
	{
		$request = $this->getRequest();
		$eventId = (int)$request->getPost('eventId');
		$parentId = (int)$request->getPost('parentId');
		$status = $request->getPost('status');
		$currentDateFrom = $request->getPost('currentDateFrom');
		$recurrentMode = $request->getPost('recursionMode');

		\CCalendarEvent::SetMeetingStatusEx([
			'attendeeId' => $this->userId,
			'eventId' => $eventId,
			'parentId' => $parentId,
			'status' => $status,
			'reccurentMode' => $recurrentMode,
			'currentDateFrom' => $currentDateFrom,
		]);

		\CCalendar::UpdateCounter([$this->userId]);

		return [
			'counters' => CountersManager::getValues($this->userId),
		];
	}

	/**
	 * @param int $eventId
	 * @param string $recursionMode
	 * @return array|true[]
	 * @throws \Bitrix\Main\Access\Exception\UnknownActionException
	 */
	public function deleteEventAction(int $eventId, string $recursionMode): array
	{
		$result = \CCalendar::deleteEvent(
			$eventId,
			true,
			[
				'recursionMode' => $recursionMode,
			]
		);

		if ($result !== true)
		{
			$this->addError(new Error('Error while delete event'));
		}

		return [
			'result' => $result,
		];
	}

	/**
	 * @param int $eventId
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function getIcsLinkAction(int $eventId): array
	{
		$connectionPath = '/bitrix/services/main/ajax.php';
		$siteId = \CSite::GetDefSite();

		$hash = \CUser::GetHitAuthHash($connectionPath, $this->userId, $siteId);
		if (!$hash)
		{
			$hash = \CUser::AddHitAuthHash($connectionPath, $this->userId, $siteId, 7200);
		}

		if (!$hash)
		{
			$this->addError(new Error('Error while trying to receive link', 404));

			return [];
		}

		$link = UrlManager::getInstance()->createByController(
			new CalendarEntryAjax(),
			'getIcsFileMobile',
			[
				'hitHash' => $hash,
				'eventId' => $eventId,
			],
			true
		);

		return [
			'link' => $link,
		];
	}

	/**
	 * @param int $eventId
	 * @param string $eventDate
	 * @param int $timezoneOffset
	 * @param array $userIds
	 * @param string $requestUsers
	 * @param string $requestCollabs
	 * @param string $getEventById
	 *
	 * @return array
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function getViewFormConfigAction(
		int $eventId,
		string $eventDate = '',
		int $timezoneOffset = 0,
		array $userIds = [],
		string $requestUsers = 'Y',
		string $requestCollabs = 'N',
		string $getEventById = 'N',
	): array
	{
		$viewFormProvider = new ViewFormProvider(
			$this->userId,
			$eventId,
			urldecode($eventDate),
			$timezoneOffset,
			$userIds,
			$requestUsers === 'Y',
			$requestCollabs === 'Y',
			$getEventById === 'Y',
		);

		$result = $viewFormProvider->getViewFormConfig();

		if (!$result->isSuccess())
		{
			$this->addError($result->getError());
		}

		return $result->getData();
	}

	/**
	 * @param int $messageId
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getEventByMessageIdAction(int $messageId): array
	{
		$forumId = (int)(\CCalendar::GetSettings()['forum_id'] ?? 0);
		if ($messageId <= 0 || $forumId <= 0 || !Loader::includeModule('forum'))
		{
			return $this->returnEventNotFound();
		}

		$message = MessageTable::query()
			->setSelect(['TOPIC_XML_ID' => 'TOPIC.XML_ID'])
			->where('ID', $messageId)
			->where('FORUM_ID', $forumId)
			->where('TOPIC.FORUM_ID', $forumId)
			->setLimit(1)
			->fetch()
		;
		$topicXmlId = is_array($message) ? (string)($message['TOPIC_XML_ID'] ?? '') : '';
		// topic xml id is written by \CCalendarEvent::GetEventCommentXmlId as <prefix><eventId>[_<eventDate>]
		$xmlIdPattern = '/^' . preg_quote(CalendarEntity::XML_ID_PREFIX, '/') . '([1-9][0-9]*)(?:_(.+))?$/D';
		if (!preg_match($xmlIdPattern, $topicXmlId, $matches))
		{
			return $this->returnEventNotFound();
		}

		$eventId = (int)$matches[1];
		if ((string)$eventId !== $matches[1])
		{
			return $this->returnEventNotFound();
		}

		$eventDate = $matches[2] ?? null;
		if ($eventDate !== null && \CCalendarEvent::ExtractDateFromCommentXmlId($topicXmlId) !== $eventDate)
		{
			return $this->returnEventNotFound();
		}

		// The lookup parameters and the two checks below repeat \CCalendarEvent::getEventForDetailsInterface()
		// on purpose: same GetList shape, same ACTION_EVENT_VIEW_FULL decision. That method is not called
		// because it also assembles the card payload this action drops - attendee list, attendee count and
		// display names - so any change of its access rules has to be mirrored here.
		// fetchAttendees has to stay on: GetList grants a meeting attendee access to the host entry by its
		// attendee list, and canViewEventDetails then decides on the very entry that lookup returned.
		$event = \CCalendarEvent::getEventForViewInterface($eventId, [
			'userId' => $this->userId,
			'eventDate' => $eventDate,
			'fetchAttendees' => true,
			'attendeeUserIds' => [$this->userId],
			'getUserfields' => false,
			'skipCache' => true,
		]);
		if (
			!is_array($event)
			|| (int)($event['ID'] ?? 0) <= 0
			|| !\CCalendarEvent::canViewEventDetails($event, $this->userId)
			|| ($eventDate !== null && \CCalendarEvent::GetEventCommentXmlId($event) !== $topicXmlId)
		)
		{
			return $this->returnEventNotFound();
		}

		$result = [
			'eventId' => $eventId,
		];
		if ($eventDate !== null)
		{
			$result['eventDate'] = $eventDate;
		}

		return $result;
	}

	/**
	 * @param int $eventId
	 * @param int $parentId
	 *
	 * @return array
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function getFilesForViewFormAction(int $eventId, int $parentId): array
	{
		$event = [
			'ID' => $eventId,
			'PARENT_ID' => $parentId,
		];

		$userFields = \CCalendarEvent::GetEventUserFields($event);
		$files = !empty($userFields['UF_WEBDAV_CAL_EVENT']) ? $userFields['UF_WEBDAV_CAL_EVENT']['VALUE'] : [];
		$fileIds = is_array($files) ? array_map(static fn ($fileId) => (int)$fileId, $files) : [];

		return [
			'files' => (new Integration\Disk\Attachment($this->userId))->getAttachments($fileIds),
		];
	}

	/**
	 * @param int $ownerId
	 * @param string $calType
	 * @param array $userIds
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getEditFormConfigAction(int $ownerId, string $calType, array $userIds = []): array
	{
		$editFormProvider = new EditFormProvider($this->userId, $ownerId, $calType, $userIds);
		$result = $editFormProvider->getEditFormConfig();

		if (!$result->isSuccess())
		{
			$this->addError($result->getError());
		}

		return $result->getData();
	}

	/**
	 * @param int $eventId
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getEventChatIdAction(int $eventId): array
	{
		$chatService = new Integration\IM\ChatService($this->userId);
		$result = $chatService->getEventChatId($eventId);

		if (!$result->isSuccess())
		{
			$this->addError($result->getError());
		}

		return $result->getData();
	}

	/**
	 * Returns the guest invite link for the event.
	 *
	 * Creates the meeting chat if it does not yet exist. Delegates to calendar
	 * GuestLinkResolver::getOrCreate and maps resolver error codes to
	 * localised Error objects.
	 *
	 * @param int $eventId
	 * @return array { url: string } on success
	 * @throws LoaderException
	 */
	public function getEventGuestLinkAction(int $eventId): array
	{
		$guestLinkService = new Integration\Calendar\GuestLinkService($this->userId);
		$result = $guestLinkService->getOrCreateGuestLink($eventId);

		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				// Do not let this user-facing action leak whether a private event exists:
				// a hidden event (NO_RIGHTS) and one that is missing or of an excluded
				// type (EVENT_NOT_SAVED) must be indistinguishable to the client.
				$code = $error->getCode();
				if ($code === GuestLinkResolver::ERROR_EVENT_NOT_SAVED)
				{
					$code = GuestLinkResolver::ERROR_NO_RIGHTS;
				}
				$messageKey = 'CALENDARMOBILE_EVENT_GUEST_LINK_ERROR_' . $code;
				$message = Loc::getMessage($messageKey);
				if ($message === null)
				{
					$message = $error->getMessage();
				}
				$this->addError(new Error($message, $code));
			}

			return [];
		}

		$data = $result->getData();

		return [
			'url' => $data['url'],
		];
	}

	public function saveHiddenSectionsAction(int $ownerId, string $calType, array $sections = []): void
	{
		$optionName = $this->userId === $ownerId && $calType === Dictionary::CALENDAR_TYPE['user']
			? 'hidden_sections'
			: 'hidden_sections_' . $calType
		;

		UserSettings::saveHiddenSections($this->userId, $sections, $optionName);
	}

	/**
	 * @param array $sections
	 * @param array $limits
	 * @return array
	 */
	private function getEvents(array $sections, array $limits): array
	{
		return \CCalendarEvent::GetList([
			'arFilter' => [
				'SECTION' => $sections,
				'FROM_LIMIT' => $limits['from'],
				'TO_LIMIT' => $limits['to'],
			],
			'parseRecursion' => false,
			'fetchAttendees' => true,
			'userId' => $this->userId,
			'setDefaultLimit' => false,
		]);
	}

	private function returnEventNotFound(): array
	{
		$this->addError(new Error('Event not found', 'EVENT_NOT_FOUND'));

		return [];
	}
}
