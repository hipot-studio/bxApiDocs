<?php

use Bitrix\Calendar\Core\Managers\Accessibility;
use Bitrix\Calendar\Rooms;
use Bitrix\Calendar\Integration\Bitrix24Manager;
use Bitrix\Calendar\Planner\AccessCodeFilter;
use Bitrix\Calendar\Sharing\SharingUser;
use Bitrix\Calendar\Util;
use Bitrix\Main\UserTable;

class CCalendarPlanner
{
	public const LIMIT_EXCEEDED_POLICY_OMIT_USERS = 'omit_users';

	private const DESTINATION_USERS_STEP_COUNT = 500;

	public static function Init($config = [], $initialParams = false)
	{
		self::InitJsCore($config, $initialParams);
	}

	public static function InitJsCore($config = [], $initialParams = [])
	{
		global $APPLICATION;
		\Bitrix\Main\UI\Extension::load(['ajax', 'window', 'popup', 'access', 'date', 'viewer', 'socnetlogdest']);
		\Bitrix\Main\UI\Extension::load(['calendar.planner', 'ui.fonts.opensans']);

		// Config
		if (!$config['id'])
		{
			$config['id'] = (isset($config['id']) && $config['id'] <> '')
				? $config['id']
				: 'bx_calendar_planner'
				. mb_substr(uniqid(mt_rand(), true), 0, 4)
			;
		}

		$APPLICATION->AddHeadScript('/bitrix/js/calendar/planner.js');
		$APPLICATION->SetAdditionalCSS("/bitrix/js/calendar/planner.css");

		$mess_lang = \Bitrix\Main\Localization\Loc::loadLanguageFile(__FILE__);
		?>
		<div id="<?= htmlspecialcharsbx($config['id'])?>" class="calendar-planner-wrapper"></div>
		<script>
			BX.namespace('BX.Calendar');
			if(typeof BX.Calendar.PlannerManager === 'undefined')
			{
				BX.Calendar.PlannerManager = {
					planners: {},
					Get: function(id)
					{
						return BX.Calendar.PlannerManager.planners[id] || false;
					},
					Init: function(id, config, initialParams)
					{
						if (window.CalendarPlanner)
						{
							BX.Calendar.PlannerManager.planners[id] = new window.CalendarPlanner(config, initialParams);
						}
					}
				}
			}

			BX.message(<?=CUtil::PhpToJSObject($mess_lang, false);?>);
			BX.ready(function()
			{
				BX.Calendar.PlannerManager.Init(
					'<?= CUtil::JSEscape($config['id'])?>',
					<?=\Bitrix\Main\Web\Json::encode($config, false);?>,
					<?=\Bitrix\Main\Web\Json::encode($initialParams);?>
				);
			});
		</script>
		<?php
	}

	public static function prepareData($params = []): array
	{
		$parentId = (int)($params['parent_id'] ?? null);
		$entryId = (int)($params['entry_id'] ?? null);

		$skipEntryId = $parentId !== 0 ? $parentId : $entryId;
		$curUserId = (int)($params['user_id'] ?? null);
		$hostUserId = (int)($params['host_id'] ?? null);
		$skipFeatureCheck = $params['skipFeatureCheck'] ?? false;

		$isPlannerFeatureEnabled = $skipFeatureCheck || Bitrix24Manager::isPlannerFeatureEnabled();

		$skipEntryList = (isset($params['skipEntryList']) && is_array($params['skipEntryList']))
			? $params['skipEntryList']
			: [];

		$resourceIdList = [];
		$userIds = [];
		$users = [];
		$destinationUserIds = [];
		$entryUserIds = [];
		$hasDestinationCodes = isset($params['codes']) && is_array($params['codes']);
		$limitExceededPolicy = $params['limitExceededPolicy'] ?? null;

		if ($hasDestinationCodes)
		{
			$codes = (new AccessCodeFilter($curUserId))->filter($params['codes']);
			// Use the legacy union for the count; expand code blocks below only until the visible prefix is full.
			$destinationUserIds = CCalendar::GetDestinationUsers($codes);
		}
		elseif (isset($params['entries']) && is_array($params['entries']))
		{
			$entryUserIds = $params['entries'];
		}

		$allUserIds = $hasDestinationCodes ? $destinationUserIds : $entryUserIds;
		$allUserIds = self::getExistingDestinationUserIds($allUserIds);
		$hasHostOutsideLimit = $limitExceededPolicy !== self::LIMIT_EXCEEDED_POLICY_OMIT_USERS
			&& $hostUserId > 0
			&& in_array($hostUserId, $allUserIds, true);
		if ($hasHostOutsideLimit)
		{
			$allUserIds = array_values(array_diff($allUserIds, [$hostUserId]));
			array_unshift($allUserIds, $hostUserId);
		}

		if ($hasDestinationCodes)
		{
			$destinationUserIds = $allUserIds;
		}
		else
		{
			$entryUserIds = $allUserIds;
		}

		$limitUserIds = $hasHostOutsideLimit ? array_slice($allUserIds, 1) : $allUserIds;
		$hasDestinationUsers = !empty($destinationUserIds);
		$peopleCount = count($allUserIds);
		$limitPeopleCount = count($limitUserIds);

		$maxPlannerUsers = CCalendar::GetMaxPlannerUsers();
		$isPlannerLimitExceeded = $maxPlannerUsers > 0 && $limitPeopleCount > $maxPlannerUsers;
		$omitUsersOnLimitExceeded = $isPlannerLimitExceeded
			&& $limitExceededPolicy === self::LIMIT_EXCEEDED_POLICY_OMIT_USERS
		;
		$visibleUserIds = $allUserIds;
		if ($omitUsersOnLimitExceeded)
		{
			$visibleUserIds = [];
		}
		elseif (
			$hasDestinationCodes
			&& $limitExceededPolicy !== self::LIMIT_EXCEEDED_POLICY_OMIT_USERS
			&& $maxPlannerUsers > 0
		)
		{
			$visibleLimitUserIds = self::getOrderedDestinationUserIds(
				$codes,
				$limitUserIds,
				min($limitPeopleCount, $maxPlannerUsers),
			);
			$visibleUserIds = $hasHostOutsideLimit
				? array_merge([$hostUserId], $visibleLimitUserIds)
				: $visibleLimitUserIds
			;
		}
		elseif ($isPlannerLimitExceeded)
		{
			if ($hasHostOutsideLimit)
			{
				$visibleUserIds = array_merge(
					[$hostUserId],
					array_slice($limitUserIds, 0, $maxPlannerUsers),
				);
			}
			else
			{
				$visibleUserIds = array_slice($limitUserIds, 0, $maxPlannerUsers);
			}
		}

		$result = [
			'users' => [],
			'entries' => [],
			'accessibility' => [],
			'plannerLimitExceeded' => $isPlannerLimitExceeded,
			'maxPlannerUsers' => $maxPlannerUsers,
			'plannerPeopleCount' => $peopleCount,
			'plannerLimitPeopleCount' => $limitPeopleCount,
		];

		$prevUsersId = (isset($params['prevUserList']) && is_array($params['prevUserList']))
			? array_unique(array_map('intval', $params['prevUserList']))
			: [];

		if ($hasDestinationUsers)
		{
			$users = self::getDestinationUserProfiles($visibleUserIds);

			if ($isPlannerLimitExceeded)
			{
				$prevUsersId = [];
			}
		}

		if ($hasDestinationUsers)
		{
			foreach($users as $user)
			{
				if (!in_array((int)$user['USER_ID'], $prevUsersId, true))
				{
					$userIds[] = (int)$user['USER_ID'];
				}

				$status = (($hostUserId && $hostUserId === (int)$user['USER_ID'])
					|| (
						!$hostUserId
						&& $limitExceededPolicy === self::LIMIT_EXCEEDED_POLICY_OMIT_USERS
						&& $curUserId === (int)$user['USER_ID']
					))
					? 'h'
					: '';

				$userSettings = \Bitrix\Calendar\UserSettings::get($user['USER_ID']);
				$result['entries'][] = [
					'type' => 'user',
					'id' => $user['USER_ID'],
					'name' => CCalendar::GetUserName($user),
					'status' => $status,
					'url' => CCalendar::GetUserUrl($user['USER_ID']),
					'avatar' => CCalendar::GetUserAvatarSrc($user),
					'strictStatus' => $userSettings['denyBusyInvitation'],
					'emailUser' => isset($user['EXTERNAL_AUTH_ID']) && ($user['EXTERNAL_AUTH_ID'] === 'email'),
					'sharingUser' => isset($user['EXTERNAL_AUTH_ID']) && ($user['EXTERNAL_AUTH_ID'] === SharingUser::EXTERNAL_AUTH_ID),
					'timezoneName' => CCalendar::GetUserTimezoneName((int)$user['USER_ID']),
					'isCollabUser' => Util::isCollabUser((int)$user['USER_ID']),
				];
			}
		}
		elseif (!$hasDestinationCodes && !empty($entryUserIds))
		{
			$userIds = $visibleUserIds;
		}

		if (isset($params['resources']) && is_array($params['resources']))
		{
			foreach($params['resources'] as $resource)
			{
				$resourceId = (int)$resource['id'];
				$resourceIdList[] = $resourceId;
				$resource['type'] = preg_replace("/[^a-zA-Z0-9_]/i", "", $resource['type']);
				$result['entries'][] = array(
					'type' => $resource['type'],
					'id' => $resourceId,
					'name' => $resource['name']
				);
				$result['accessibility'][$resourceId] = [];
			}
		}

		$from = $params['date_from'] ?? null;
		$to = $params['date_to'] ?? null;

		if (empty($from) || empty($to))
		{
			return $result;
		}

		if ($isPlannerFeatureEnabled)
		{
			$accessibility = (new Accessibility())
				->setSkipEventId($skipEntryId)
				->getAccessibility($userIds, CCalendar::TimestampUTC($from), CCalendar::TimestampUTC($to))
			;

			$timezoneName = \CCalendar::GetUserTimezoneName(\CCalendar::GetUserId());
			foreach ($accessibility as $userId => $entries)
			{
				$result['accessibility'][$userId] = [];
				foreach ($entries as $entry)
				{
					if (isset($entry['id']) && in_array($entry['id'], $skipEntryList))
					{
						continue;
					}

					if ($entry['isFullDay'])
					{
						$dateFrom = $entry['from'];
						$dateTo = $entry['to'];
					}
					else
					{
						$dateFrom = Util::formatDateTimeTimestamp(CCalendar::TimestampUTC($entry['from']), $timezoneName);
						$dateTo = Util::formatDateTimeTimestamp(CCalendar::TimestampUTC($entry['to']), $timezoneName);
					}

					$result['accessibility'][$userId][] = [
						'name' => $entry['name'],
						'dateFrom' => $dateFrom,
						'dateTo' => $dateTo,
						'isFullDay' => $entry['isFullDay'],
						'isVacation' => $entry['isVacation'] ?? false,
					];
				}
			}
		}

		if (isset($params['location']))
		{
			$location = \Bitrix\Calendar\Rooms\Util::parseLocation($params['location']);
			$entryLocation = \Bitrix\Calendar\Rooms\Util::parseLocation($params['entryLocation'] ?? null);
			$roomEventId = $entryLocation['room_event_id'] ?? null;

			if ($roomEventId && !in_array($roomEventId, $skipEntryList))
			{
				$skipEntryList[] = $roomEventId;
			}

			if ($location['mrid'] ?? null)
			{
				$mrid = 'MR_' . $location['mrid'];
				$entry = [
					'type' => 'room',
					'id' => $mrid,
					'name' => 'meeting room'
				];

				$roomList = Rooms\IBlockMeetingRoom::getMeetingRoomList();
				foreach ($roomList as $room)
				{
					if ((int)$room['ID'] === (int)$location['mrid'])
					{
						$entry['name'] = $room['NAME'];
						$entry['url'] = $room['URL'];
						break;
					}
				}

				$result['entries'][] = $entry;
				$result['accessibility'][$mrid] = [];

				if ($isPlannerFeatureEnabled)
				{
					$meetingRoomRes = Rooms\IBlockMeetingRoom::getAccessibilityForMeetingRoom([
						'allowReserveMeeting' => true,
						'id' => $location['mrid'],
						'from' => $from,
						'to' => $to,
						'curEventId' => $roomEventId
					]);

					foreach ($meetingRoomRes as $entry)
					{
						if (!in_array($entry['ID'], $skipEntryList))
						{
							$result['accessibility'][$mrid][] = [
								'id' => $entry['ID'],
								'dateFrom' => $entry['DT_FROM'],
								'dateTo' => $entry['DT_TO']
							];
						}
					}
				}
			}
			elseif ($location['room_id'])
			{
				$roomId = 'room_' . $location['room_id'];
				$entry = [
					'type' => 'room',
					'id' => $roomId,
					'roomId' => $location['room_id'],
					'name' => 'meeting room'
				];

				$sectionList = Rooms\Manager::getRoomsList();
				foreach($sectionList as $room)
				{
					if ((int)$room['ID'] === (int)$location['room_id'])
					{
						$entry['name'] = $room['NAME'];
						break;
					}
				}

				$result['entries'][] = $entry;
				$result['accessibility'][$roomId] = [];

				if ($isPlannerFeatureEnabled)
				{
					$meetingRoomRes = Rooms\AccessibilityManager::getRoomAccessibility(
						[$location['room_id']],
						$from,
						$to
					);

					foreach ($meetingRoomRes as $entry)
					{
						if ((int)$entry['PARENT_ID'] === $skipEntryId || in_array((int)$entry['ID'], $skipEntryList))
						{
							continue;
						}

						$dateFrom = $entry['DATE_FROM'];
						if ($entry['DT_SKIP_TIME'] !== "Y")
						{
							$dateFrom = CCalendar::Date(
								CCalendar::Timestamp($entry['DATE_FROM']) - $entry['~USER_OFFSET_FROM']
							);
							$dateTo = CCalendar::Date(
								CCalendar::Timestamp($entry['DATE_TO']) - $entry['~USER_OFFSET_TO']
							);
						}
						else
						{
							$dateTo = CCalendar::Date(
								CCalendar::Timestamp($entry['DATE_TO']) + CCalendar::GetDayLen()
							);
						}

						$result['accessibility'][$roomId][] = [
							'id' => $entry['ID'],
							'name' => $entry['NAME'],
							'dateFrom' => $dateFrom,
							'dateTo' => $dateTo
						];
					}
				}
			}
		}

		if (!empty($resourceIdList) && $isPlannerFeatureEnabled)
		{
			$resEntries = CCalendarEvent::GetList(
				array(
					'arFilter' => array(
						"FROM_LIMIT" => $from,
						"TO_LIMIT" => $to,
						"CAL_TYPE" => 'resource',
						"ACTIVE_SECTION" => "Y",
						"SECTION" => $resourceIdList
					),
					'parseRecursion' => true,
					'setDefaultLimit' => false
				)
			);

			foreach($resEntries as $entry)
			{
				if (in_array($entry['ID'], $skipEntryList))
				{
					continue;
				}

				$dateFrom = $entry['DATE_FROM'];
				$dateTo = $entry['DATE_TO'];

				if ($entry['DT_SKIP_TIME'] !== "Y")
				{
					$dateFrom = CCalendar::Date(
						CCalendar::Timestamp($entry['DATE_FROM'])
						- $entry['~USER_OFFSET_FROM']
					);
					$dateTo = CCalendar::Date(
						CCalendar::Timestamp($entry['DATE_TO'])
						- $entry['~USER_OFFSET_TO']
					);
				}

				$result['accessibility'][$entry['SECT_ID']][] = array(
					'id' => $entry["ID"],
					'name' => $entry["NAME"],
					'dateFrom' => $dateFrom,
					'dateTo' => $dateTo
				);
			}
		}

		if (($params['initPullWatches'] ?? null) === true)
		{
			Util::initPlannerPullWatches(
				$curUserId,
				$userIds
			);
		}

		return $result;
	}

	private static function getExistingDestinationUserIds(array $userIds): array
	{
		$normalizedUserIds = [];
		foreach ($userIds as $userId)
		{
			if (
				!is_int($userId)
				&& (
					!is_string($userId)
					|| preg_match('/^[0-9]+$/D', $userId) !== 1
					|| ltrim($userId, '0') !== (string)(int)$userId
				)
			)
			{
				continue;
			}

			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalizedUserIds[$userId] = $userId;
			}
		}

		if (empty($normalizedUserIds))
		{
			return [];
		}

		$existingUserIds = [];
		foreach (array_chunk(array_values($normalizedUserIds), self::DESTINATION_USERS_STEP_COUNT) as $chunk)
		{
			$users = UserTable::getList([
				'select' => ['ID'],
				'filter' => ['@ID' => $chunk],
			]);

			foreach ($users as $user)
			{
				$existingUserIds[(int)$user['ID']] = true;
			}
		}

		return array_values(array_filter(
			$normalizedUserIds,
			static fn (int $userId): bool => isset($existingUserIds[$userId]),
		));
	}

	private static function getOrderedDestinationUserIds(
		array $codes,
		array $availableUserIds,
		int $limit,
	): array
	{
		if ($limit <= 0 || empty($availableUserIds))
		{
			return [];
		}

		$availableUserIds = array_fill_keys($availableUserIds, true);
		$orderedUserIds = [];
		foreach ($codes as $code)
		{
			if (count($orderedUserIds) >= $limit)
			{
				break;
			}

			if (is_string($code) && preg_match('/^U([1-9][0-9]*)\z/', $code, $match) === 1)
			{
				$codeUserIds = [(int)$match[1]];
			}
			else
			{
				$codeUserIds = array_values(array_filter(
					array_map('intval', CCalendar::GetDestinationUsers([$code])),
					static fn (int $userId): bool => $userId > 0,
				));
				sort($codeUserIds, SORT_NUMERIC);
			}

			foreach ($codeUserIds as $userId)
			{
				if (isset($availableUserIds[$userId]))
				{
					$orderedUserIds[$userId] = $userId;
				}

				if (count($orderedUserIds) >= $limit)
				{
					break 2;
				}
			}
		}

		if (count($orderedUserIds) < $limit)
		{
			foreach ($availableUserIds as $userId => $unused)
			{
				$orderedUserIds[$userId] = $userId;
				if (count($orderedUserIds) >= $limit)
				{
					break;
				}
			}
		}

		return array_values($orderedUserIds);
	}

	private static function getDestinationUserProfiles(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$profiles = CCalendar::GetDestinationUsers(
			array_map(static fn (int $userId): string => 'U' . $userId, $userIds),
			true,
		);
		$profilesByUserId = [];
		foreach ($profiles as $profile)
		{
			$profilesByUserId[(int)$profile['USER_ID']] = $profile;
		}

		$orderedProfiles = [];
		foreach ($userIds as $userId)
		{
			if (isset($profilesByUserId[$userId]))
			{
				$orderedProfiles[] = $profilesByUserId[$userId];
			}
		}

		return $orderedProfiles;
	}
}
