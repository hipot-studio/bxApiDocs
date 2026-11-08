<?php

namespace Bitrix\Calendar\Internals\Helper;

use Bitrix\Calendar\Core\Base\SingletonTrait;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\ArgumentException;

final class Analytics
{
	use SingletonTrait;

	protected const TOOL = 'calendar';

	protected const CALENDAR_CATEGORY = 'calendar';

	protected const EVENT = [
		'create_event' => 'create_event',
		'accept_event' => 'accept_event',
		'decline_event' => 'decline_event',
	];

	public const SECTION = [
		'calendar' => 'calendar',
		'chat' => 'chat',
		'collab' => 'collab',
		'project' => 'project',
		'crm' => 'crm',
		'tasks' => 'tasks',
	];

	public const SUB_SECTION = [
		'calendar_personal' => 'calendar_personal',
		'calendar_collab' => 'calendar_collab',
		'calendar_project' => 'calendar_project',
		'calendar' => 'calendar',
		'chat_textarea' => 'chat_textarea',
		'stream' => 'stream',
	];

	public const USER_TYPES = [
		'intranet' => 'user_intranet',
		'extranet' => 'user_extranet',
		'collaber' => 'user_collaber',
	];

	public const EVENT_TYPE = [
		'group' => 'group',
		'single' => 'single',
	];

	/**
	 * @param string $type Event type: 'group' or 'single'
	 * @param string $section
	 * @param string|null $subSection
	 * @param string|null $userType
	 * @param int|null $groupId Group owner id for group calendar events (used as projectId)
	 * @param int|null $chatId
	 *
	 * @return void
	 * @throws ArgumentException
	 */
	public function onEventCreate(
		string $type,
		string $section,
		?string $subSection,
		?string $userType = null,
		?int $groupId = 0,
		?int $chatId = 0,
	): void
	{
		$analyticsEvent = new AnalyticsEvent(
			event: self::EVENT['create_event'],
			tool: self::TOOL,
			category: self::CALENDAR_CATEGORY
		);

		$analyticsEvent->setType($type);

		$params = [];

		if (!empty($userType))
		{
			$params['p2'] = $userType;
		}

		if (!empty($groupId))
		{
			$params['p4'] = 'projectId_' . $groupId;
		}

		if (!empty($chatId))
		{
			$params['p5'] = 'chatId_' . $chatId;
		}

		$this->sendAnalytics($analyticsEvent, $section, $subSection, $params);
	}

	/**
	 * @param int $eventId
	 * @param int $userId
	 * @param int|null $groupId Group owner id for project context (used as projectId)
	 * @param string|null $subSection Context source sub-section
	 * @param string|null $userType
	 * @param string $status 'success' or 'error'
	 *
	 * @return void
	 * @throws ArgumentException
	 */
	public function onEventAccept(
		int $eventId,
		int $userId,
		?int $groupId = 0,
		?string $subSection = null,
		?string $userType = null,
		string $status = AnalyticsEvent::STATUS_SUCCESS,
	): void
	{
		$this->sendMeetingStatusEvent(
			eventName: self::EVENT['accept_event'],
			groupId: $groupId,
			subSection: $subSection,
			userType: $userType,
			status: $status,
		);
	}

	/**
	 * @param int $eventId
	 * @param int $userId
	 * @param int|null $groupId Group owner id for project context (used as projectId)
	 * @param string|null $subSection Context source sub-section
	 * @param string|null $userType
	 * @param string $status 'success' or 'error'
	 *
	 * @return void
	 * @throws ArgumentException
	 */
	public function onEventDecline(
		int $eventId,
		int $userId,
		?int $groupId = 0,
		?string $subSection = null,
		?string $userType = null,
		string $status = AnalyticsEvent::STATUS_SUCCESS,
	): void
	{
		$this->sendMeetingStatusEvent(
			eventName: self::EVENT['decline_event'],
			groupId: $groupId,
			subSection: $subSection,
			userType: $userType,
			status: $status,
		);
	}

	/**
	 * @param AnalyticsEvent $analyticsEvent
	 * @param string|null $section
	 * @param string|null $subSection
	 * @param array $params
	 *
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 */
	private function sendAnalytics(
		AnalyticsEvent $analyticsEvent,
		?string $section = null,
		?string $subSection = null,
		array $params = [],
	): void
	{
		if (in_array($section, self::SECTION, true))
		{
			$analyticsEvent->setSection($section);
		}

		if (in_array($subSection, self::SUB_SECTION, true))
		{
			$analyticsEvent->setSubSection($subSection);
		}

		for ($i = 1; $i <= 5; $i++)
		{
			$pKey = 'p' . $i;
			if (!empty($params[$pKey]) && is_string($params[$pKey]))
			{
				$methodName = 'setP' . ($i);
				if (method_exists($analyticsEvent, $methodName))
				{
					$analyticsEvent->$methodName($params[$pKey]);
				}
			}
		}

		$analyticsEvent->send();
	}

	/**
	 * @throws ArgumentException
	 */
	private function sendMeetingStatusEvent(
		string $eventName,
		?int $groupId,
		?string $subSection,
		?string $userType,
		string $status,
	): void
	{
		$analyticsEvent = new AnalyticsEvent(
			event: $eventName,
			tool: self::TOOL,
			category: self::CALENDAR_CATEGORY
		);

		$analyticsEvent->setStatus($status);

		$params = [];

		if (!empty($userType))
		{
			$params['p2'] = $userType;
		}

		if (!empty($groupId))
		{
			$params['p4'] = 'projectId_' . $groupId;
		}

		$this->sendAnalytics($analyticsEvent, self::SECTION['project'], $subSection, $params);
	}
}
