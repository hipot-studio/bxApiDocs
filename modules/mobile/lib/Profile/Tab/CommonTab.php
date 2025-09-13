<?php

namespace Bitrix\Mobile\Profile\Tab;

use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Profile\Enum\TabContextType;
use Bitrix\Mobile\Profile\Enum\TabType;
use Bitrix\Mobile\Profile\Enum\UserStatus;
use Bitrix\Mobile\Profile\Provider\GratitudeProvider;
use Bitrix\Mobile\Provider\CommonUserDto;
use Bitrix\Mobile\Provider\UserRepository;

class CommonTab extends BaseProfileTab
{
	/**
	 * @return TabType
	 */
	public function getType(): TabType
	{
		return TabType::COMMON;
	}

	/**
	 * @return TabContextType
	 */
	public function getContextType(): TabContextType
	{
		return TabContextType::WIDGET;
	}

	/**
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		return true;
	}

	/**
	 * @return string
	 */
	public function getTitle(): string
	{
		return Loc::getMessage('PROFILE_TAB_COMMON_TITLE');
	}

	/**
	 * @return array
	 */
	public function getParams(): array
	{
		return [
			'ownerId' => $this->ownerId,
		];
	}

	/**
	 * @return array
	 * @throws LoaderException
	 */
	public function getData(): array
	{
		// todo: check profile view permissions
		$users = UserRepository::getByIds([$this->ownerId]);
		if (!empty($users))
		{
			$statusData = $this->getOwnerStatusData($users[0]);

			return [
				'user' => $users[0],
				'statusData' => $statusData,
				'gratitude' => $this->getGratitudeData(),
			];
		}

		return [];
	}

	/**
	 * @param \Bitrix\Mobile\Provider\CommonUserDto $owner
	 * @return array
	 */
	private function getOwnerStatusData(CommonUserDto $owner): array
	{
		$result = [
			'onVacationDateTo' => '',
			'lastSeenText' => '',
		];

		$isFired = $this->isOwnerFired();
		if ($isFired)
		{
			$result['status'] = UserStatus::FIRED;

			return $result;
		}

		$onVacationDateTo = $this->getMaxDateToFromAbsences($this->getOwnerAbsences());
		if (!empty($onVacationDateTo))
		{
			$result['status'] = UserStatus::ON_VACATION;
			$result['onVacationDateTo'] = $onVacationDateTo;

			return $result;
		}

		$onlineStatus = $this->getOnlineStatus($owner->lastActivityDate);
		$result['lastSeenText'] = $onlineStatus['LAST_SEEN_TEXT'];
		$result['lastSeenDate'] = $onlineStatus['LAST_SEEN'];

		if ($onlineStatus['STATUS'] === 'dnd')
		{
			$result['status'] = UserStatus::DND;
			$result['GMTString'] = $this->getGMTString($owner->timezone);

			return $result;
		}

		if ($onlineStatus['IS_ONLINE'])
		{
			$result['status'] = UserStatus::ONLINE;
			$result['GMTString'] = $this->getGMTString($owner->timezone);

			return $result;
		}

		$result['status'] = UserStatus::OFFLINE;

		return $result;
	}

	/**
	 * @param \DateTimeZone $timezone
	 * @return string
	 * @throws \DateMalformedStringException
	 */
	private function getGMTString(\DateTimeZone $timezone): string
	{
		$dateTime = new \DateTime('now', $timezone);
		$offsetInSeconds = $timezone->getOffset($dateTime);
		$hours = intdiv($offsetInSeconds, 3600);
		$minutes = abs($offsetInSeconds % 3600) / 60;

		$gmt = sprintf("GMT%+d:%02d", $hours, $minutes);
		$time = $dateTime->format('H:i');

		return "{$gmt} ({$time})";
	}

	/**
	 * @param string $lastActivityDate
	 * @return array
	 */
	private function getOnlineStatus(string $lastActivityDate): array
	{
		return \CUser::GetOnlineStatus(
			$this->ownerId,
			MakeTimeStamp(empty($lastActivityDate) ? null : $lastActivityDate, "DD.MM.YYYY HH:MI:SS"),
		);
	}

	/**
	 * @param array $absenceData
	 * @return string
	 */
	private function getMaxDateToFromAbsences(array $absenceData): string
	{
		if (!empty($absenceData) && $absenceData['IS_VACATION'] === true)
		{
			return (new \DateTime())
				->setTimestamp($absenceData['DATE_TO_TS'])
				->format('d.m.Y');
		}

		return '';
	}

	private function getOwnerAbsences(): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		$result = \Bitrix\Intranet\UserAbsence::isAbsentOnVacation($this->ownerId, true);
		if (empty($result) || !is_array($result))
		{
			return [];
		}

		return $result;
	}

	/**
	 * @return bool
	 */
	private function isOwnerFired(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}
		$this->userRepository = ServiceContainer::getInstance()->userRepository();
		$intranetUser = $this->userRepository->findUsersByIds([$this->ownerId])->first();
		if (empty($intranetUser))
		{
			return false;
		}

		return $intranetUser->getInviteStatus() === InvitationStatus::FIRED;
	}

	/**
	 * @return array
	 * @throws LoaderException
	 */
	private function getGratitudeData(): array
	{
		$gratitudeProvider = new GratitudeProvider();
		$limit = 10;

		return $gratitudeProvider->getBadges($this->ownerId, $limit);
	}
}
