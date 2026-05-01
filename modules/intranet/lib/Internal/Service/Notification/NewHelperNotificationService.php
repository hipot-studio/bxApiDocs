<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Notification;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internal\Integration\Bitrix24\PortalInfoService;
use Bitrix\Main\Config\Option;

class NewHelperNotificationService implements NotificationService
{
	private const MODULE_ID = 'intranet';
	protected const OPTION_IS_NEW_HELPDESK = 'isNewHelpdesk';
	protected const OPTION_START_TS = 'new_helper_notification_start_ts';
	protected const OPTION_END_TS = 'new_helper_notification_end_ts';
	protected const USER_OPTION_CATEGORY = 'intranet';
	protected const USER_OPTION_NAME = 'new_helper_notification_shown';
	protected const USER_OPTION_VALUE = 'Y';

	public function __construct(
		private readonly ?CurrentUser $currentUser,
		private readonly ?PortalInfoService $portalInfoService,
		private readonly ?int $currentTimestamp,
	)
	{
	}

	public static function createForCurrentUser(): self
	{
		return new self(
			CurrentUser::get(),
			new PortalInfoService(),
			time(),
		);
	}

	public function isAvailable(): bool
	{
		if (Option::get(self::MODULE_ID, self::OPTION_IS_NEW_HELPDESK, 'N') !== 'Y')
		{
			return false;
		}

		$period = $this->getDisplayPeriod();

		if ($period === null)
		{
			return false;
		}

		return
			$this->currentTimestamp >= $period['start']
			&& $this->currentTimestamp <= $period['end'];
	}

	public function needToShow(): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		if (!$this->currentUser->isAuthorized())
		{
			return false;
		}

		$userId = (int)$this->currentUser->getId();

		if ($userId <= 0 || $this->isAlreadyShownForUser($userId))
		{
			return false;
		}

		$period = $this->getDisplayPeriod();

		if ($period === null)
		{
			return false;
		}

		$portalCreationDate = $this->portalInfoService->getCreationDateTime();

		if ($portalCreationDate === null || $portalCreationDate->getTimestamp() > $period['start'])
		{
			return false;
		}

		$userRegistrationDate = $this->currentUser->getDateRegister();

		if ($userRegistrationDate === null || $userRegistrationDate->getTimestamp() > $period['start'])
		{
			return false;
		}

		return true;
	}

	public function setShownForUser(): void
	{
		\CUserOptions::SetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_NAME,
			self::USER_OPTION_VALUE,
			false,
			$this->currentUser->getId(),
		);
	}

	/**
	 * @return array{start: int, end: int}|null
	 */
	private function getDisplayPeriod(): ?array
	{
		$startTimestamp = $this->getPositiveIntegerOptionValue(self::OPTION_START_TS);
		$endTimestamp = $this->getPositiveIntegerOptionValue(self::OPTION_END_TS);

		if ($startTimestamp === null || $endTimestamp === null || $startTimestamp > $endTimestamp)
		{
			return null;
		}

		return [
			'start' => $startTimestamp,
			'end' => $endTimestamp,
		];
	}

	private function getPositiveIntegerOptionValue(string $optionName): ?int
	{
		$rawValue = Option::get(self::MODULE_ID, $optionName);
		$value = (int)$rawValue;

		return $value > 0 ? $value : null;
	}

	private function isAlreadyShownForUser(int $userId): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_NAME,
			'N',
			$userId,
		) === self::USER_OPTION_VALUE;
	}
}
