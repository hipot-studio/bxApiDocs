<?php

namespace Bitrix\Calendar\Application;

use Bitrix\Calendar\Application\Command\BusyAttendees;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Core\Managers\Accessibility;
use Bitrix\Calendar\Core\Section\Section;
use Bitrix\Calendar\Integration\HumanResources\TeamAccessService;
use Bitrix\Calendar\Internals\Exception\AttendeeBusy;
use Bitrix\Calendar\UserSettings;
use Bitrix\Calendar\Util;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\ProjectProvider;
use Bitrix\Socialnetwork\UserToGroupTable;

class AttendeeService
{
	public function getAttendeeAccessCodes(?array $attendeesEntityList, int $userId): array
	{
		$codes = [];
		if (is_array($attendeesEntityList))
		{
			$attendeesEntityList = $this->filterViewableTeams($attendeesEntityList, $userId);
			$attendeesEntityList = $this->filterSelectableProjects($attendeesEntityList, $userId);
			$codes = Util::convertEntitiesToCodes($attendeesEntityList);
		}

		return \CCalendarEvent::handleAccessCodes($codes, ['userId' => $userId]);
	}

	/**
	 * Team codes (SNT<id>) expand into member lists, so a user must not attach a team they
	 * cannot view. Gate structure-node entities by team-view permission for the event author
	 * (deny-by-default), symmetrically to the planner branch in CalendarAjax.
	 */
	private function filterViewableTeams(array $attendeesEntityList, int $userId): array
	{
		$teamNodeIds = [];
		foreach ($attendeesEntityList as $entity)
		{
			if (is_array($entity) && ($entity['entityId'] ?? null) === 'structure-node')
			{
				$nodeId = (int)($entity['id'] ?? 0);
				if ($nodeId > 0)
				{
					$teamNodeIds[$nodeId] = $nodeId;
				}
			}
		}

		$viewableTeamIndex = empty($teamNodeIds)
			? []
			: array_fill_keys(
				(new TeamAccessService())->getViewableTeamIds(array_values($teamNodeIds), $userId),
				true,
			)
		;

		$filtered = [];
		foreach ($attendeesEntityList as $entity)
		{
			if (is_array($entity) && ($entity['entityId'] ?? null) === 'structure-node')
			{
				if (!isset($viewableTeamIndex[(int)($entity['id'] ?? 0)]))
				{
					continue;
				}
			}

			$filtered[] = $entity;
		}

		return $filtered;
	}

	public function getNotSelectableProjectAccessCodes(array|string $accessCodes, int $userId): array
	{
		if (is_string($accessCodes))
		{
			$accessCodes = explode(',', $accessCodes);
		}

		$projectIds = [];
		foreach ($accessCodes as $code)
		{
			$projectId = $this->getProjectId($code);
			if ($projectId !== null)
			{
				$projectIds[$projectId] = $projectId;
			}
		}

		$selectableProjectIds = $this->getSelectableProjectIds($projectIds, $userId);
		$notSelectableAccessCodes = [];
		foreach ($accessCodes as $code)
		{
			$projectId = $this->getProjectId($code);
			if ($projectId !== null && !isset($selectableProjectIds[$projectId]))
			{
				$notSelectableAccessCodes[] = $code;
			}
		}

		return $notSelectableAccessCodes;
	}

	private function filterSelectableProjects(array $attendeesEntityList, int $userId): array
	{
		$projectIds = [];
		foreach ($attendeesEntityList as $entity)
		{
			if (!is_array($entity) || !in_array($entity['entityId'] ?? null, ['project', 'project-roles'], true))
			{
				continue;
			}

			$projectId = $this->getProjectId($entity['id'] ?? null);
			if ($projectId !== null)
			{
				$projectIds[$projectId] = $projectId;
			}
		}

		$selectableProjectIds = $this->getSelectableProjectIds($projectIds, $userId);
		$filtered = [];
		foreach ($attendeesEntityList as $entity)
		{
			if (is_array($entity) && in_array($entity['entityId'] ?? null, ['project', 'project-roles'], true))
			{
				$projectId = $this->getProjectId($entity['id'] ?? null);
				if ($projectId === null || !isset($selectableProjectIds[$projectId]))
				{
					continue;
				}
			}

			$filtered[] = $entity;
		}

		return $filtered;
	}

	private function getSelectableProjectIds(array $projectIds, int $userId): array
	{
		if (empty($projectIds) || $userId <= 0 || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		try
		{
			$projects = ProjectProvider::getProjects([
				'projectId' => array_values($projectIds),
				'currentUserId' => $userId,
			]);
			$selectableProjectIds = [];
			foreach ($projects as $project)
			{
				$projectId = $project->getId();
				$selectableProjectIds[$projectId] = $projectId;
			}

			if (empty($selectableProjectIds))
			{
				return [];
			}

			$memberProjectIds = [];
			$membershipResult = UserToGroupTable::getList([
				'select' => ['GROUP_ID'],
				'filter' => [
					'=USER_ID' => $userId,
					'@GROUP_ID' => array_values($selectableProjectIds),
					'<=ROLE' => UserToGroupTable::ROLE_USER,
				],
			]);
			while ($membership = $membershipResult->fetch())
			{
				$projectId = (int)$membership['GROUP_ID'];
				$memberProjectIds[$projectId] = $projectId;
			}

			return $memberProjectIds;
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	private function getProjectId(mixed $value): ?int
	{
		if (!is_int($value) && !is_string($value))
		{
			return null;
		}

		if (preg_match('/^SG([1-9][0-9]*)(?:_?[AEKMO])?$/', (string)$value, $matches) === 1)
		{
			return (int)$matches[1];
		}

		if (preg_match('/^([1-9][0-9]*)(?:_?[AEKMO])?$/', (string)$value, $matches) === 1)
		{
			return (int)$matches[1];
		}

		return null;
	}

	public function isMeeting(array $accessCodes, Section $section, int $userId): bool
	{
		$calType = $section->getType() ?? '';

		return $accessCodes !== ['U'.$userId]
			|| in_array($calType, ['group', 'company_calendar', Dictionary::CALENDAR_TYPE['open_event']], true);
	}

	public function excludeAttendees(array $attendees, array $attendeesCodes, ?string $paramExcludeUsers): array
	{
		$excludeUsers = $this->getExcludeUsers($attendees, $paramExcludeUsers);
		if (!empty($excludeUsers))
		{
			$attendeesCodes = [];
			$attendees = array_diff($attendees, $excludeUsers);
			foreach($attendees as $attendee)
			{
				$attendeesCodes[] = 'U'. (int)$attendee;
			}
		}

		return [
			'attendees' => $attendees,
			'codes' => $attendeesCodes,
		];
	}

	public function getExcludeUsers(?array $attendees, ?string $paramsExcludeUsers): array
	{
		$excludeUsers = [];
		if ($paramsExcludeUsers && !empty($attendees))
		{
			$excludeUsers = explode(',', $paramsExcludeUsers);
		}

		return $excludeUsers;
	}

	/**
	 * @throws AttendeeBusy
	 */
	public function checkBusyAttendees(BusyAttendees $command, ?array $paramAttendees, ?int $eventId = null, array $additionalExcludeUsers = []): void
	{
		$attendees = [];
		$eventId = $eventId ?? 0;

		if ($command->isCheckCurrentUsersAccessibility())
		{
			$attendees = $paramAttendees;
		}
		else if (is_array($command->getNewAttendeesList()))
		{
			$excludedUsers = explode(',', $command->getExcludeUsers());
			$attendees = array_diff($command->getNewAttendeesList(), $excludedUsers);
		}

		$timezoneFrom = !empty($command->getTimeZoneFrom()) ? $command->getTimeZoneFrom() : \CCalendar::GetUserTimezoneName(\CCalendar::GetUserId());
		$timezoneTo = !empty($command->getTimeZoneTo()) ? $command->getTimeZoneTo() : $timezoneFrom;
		$timezoneOffsetFrom = Util::getTimezoneOffsetUTC($timezoneFrom);
		$timezoneOffsetTo = Util::getTimezoneOffsetUTC($timezoneTo);
		$timestampFrom = \CCalendar::TimestampUTC($command->getDateFrom()) - $timezoneOffsetFrom;
		$timestampTo = \CCalendar::TimestampUTC($command->getDateTo()) - $timezoneOffsetTo;
		if ($command->isSkipTime())
		{
			$timestampTo += \CCalendar::GetDayLen();
		}

		$busyUsers = $this->getBusyUsersIds($attendees, $eventId, $timestampFrom, $timestampTo, $additionalExcludeUsers);
		if (!empty($busyUsers))
		{
			$busyUsersList = \CCalendarEvent::getUsersDetails($busyUsers);
			$busyUserName = current($busyUsersList)['DISPLAY_NAME'];
			throw (new AttendeeBusy())
				->setBusyUsersList($busyUsersList)
				->setAttendeeName($busyUserName)
			;
		}
	}

	private function getBusyUsersIds(array $attendees, int $curEventId, int $fromTs, int $toTs, array $additionalExcludeUsers): array
	{
		$usersToCheck = $this->getUsersToCheck($attendees, $additionalExcludeUsers);
		if (empty($usersToCheck))
		{
			return [];
		}

		return (new Accessibility())
			->setSkipEventId($curEventId)
			->getBusyUsersIds($usersToCheck, $fromTs, $toTs);
	}

	private function getUsersToCheck(array $attendees, array $additionalExcludeUsers): array
	{
		$usersToCheck = [];
		foreach ($attendees as $attId)
		{
			$attendeeId = (int)$attId;
			if ($attendeeId !== \CCalendar::GetUserId() && !in_array($attendeeId, $additionalExcludeUsers, true))
			{
				$userSettings = UserSettings::get($attendeeId);
				if ($userSettings && $userSettings['denyBusyInvitation'])
				{
					$usersToCheck[] = $attendeeId;
				}
			}
		}
		return $usersToCheck;
	}
}
