<?php

namespace Bitrix\CalendarMobile\Provider;

use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Integration\Bitrix24\FeatureDictionary;
use Bitrix\Calendar\Integration\Bitrix24Manager;
use Bitrix\Calendar\Rooms;
use Bitrix\Calendar\Util;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Mobile\Provider\UserRepository;

final class EditFormProvider
{
	public function __construct(
		private readonly int $userId,
		private readonly int $ownerId,
		private readonly string $calType,
		private readonly array $userIdsToRequest = [],
	)
	{
	}

	/**
	 * @return Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getEditFormConfig(): Result
	{
		$result = new Result();

		if (!$this->checkToolAvailability())
		{
			return $result->addError(new Error('Tool not available'));
		}

		$baseInfoProvider = new BaseInfoProvider($this->userId, $this->ownerId, $this->calType);
		if (!$baseInfoProvider->checkPermissions())
		{
			return $result->addError(new Error('Permission denied'));
		}

		$locationFeatureEnabled = Bitrix24Manager::isFeatureEnabled(FeatureDictionary::CALENDAR_LOCATION);
		$locationList = $locationFeatureEnabled ? Rooms\Manager::getRoomsList() : [];
		$categoryList = $locationFeatureEnabled ? Rooms\Categories\Manager::getCategoryList() : [];
		$users = !empty($this->userIdsToRequest) ? UserRepository::getByIds($this->userIdsToRequest) : [];

		return $result->setData([
			'user' => UserRepository::getByIds([$this->userId]),
			'users' => $users,
			'sections' => $this->getSections($baseInfoProvider),
			'settings' => $baseInfoProvider->getCalendarSettings(),
			'meetSection' => $this->getMeetingSection(),
			'firstWeekday' => $this->getFirstWeekday(),
			'locationList' => $locationList,
			'categoryList' => $categoryList,
		]);
	}

	/**
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function checkToolAvailability(): bool
	{
		return !(Loader::includeModule('intranet')
			&& !ToolsManager::getInstance()->checkAvailabilityByToolId('calendar'))
		;
	}

	/**
	 * @param BaseInfoProvider $baseInfoProvider
	 *
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function getSections(BaseInfoProvider $baseInfoProvider): array
	{
		$isCollabContext = Util::isCollabUser($this->userId)
			&& $this->calType === Dictionary::CALENDAR_TYPE['user']
		;

		if ($isCollabContext)
		{
			$userCollabIds = $baseInfoProvider->getCollabIds();

			if (empty($userCollabIds))
			{
				return [];
			}

			$sectionList = $baseInfoProvider->getCollabSections($userCollabIds, [
				'ACTIVE' => 'Y',
			]);
		}
		else
		{
			$sectionList = $baseInfoProvider->getMergedSections([
				'checkPermissions' => true,
				'getPermissions' => true,
			]);
		}

		$sections = [];
		foreach ($sectionList as $section)
		{
			if ($section['PERM']['edit'] || $section['PERM']['add'])
			{
				$sections[] = $section;
			}
		}

		if (!empty($sections) || !empty($sectionList))
		{
			return $sections;
		}

		if ($isCollabContext)
		{
			$sections[] = \CCalendarSect::createDefault([
				'type' => Dictionary::CALENDAR_TYPE['group'],
				'ownerId' => current($userCollabIds),
			]);
		}
		else
		{
			$sections[] = \CCalendarSect::createDefault([
				'type' => $this->calType,
				'ownerId' => $this->ownerId,
			]);
		}

		return $sections;
	}

	private function getMeetingSection(): int
	{
		$result = null;

		if ($this->calType === Dictionary::CALENDAR_TYPE['user'])
		{
			$result = \CCalendar::GetMeetingSection($this->userId);
		}

		return (int)$result;
	}

	private function getFirstWeekday(): int
	{
		$weekDayIndex = [
			'SU' => 1,
			'MO' => 2,
			'TU' => 3,
			'WE' => 4,
			'TH' => 5,
			'FR' => 6,
			'SA' => 7,
		];

		$weekDay = \CCalendar::GetWeekStart();

		return $weekDayIndex[$weekDay];
	}
}
