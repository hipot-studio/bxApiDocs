<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Internal\WorkReport;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\StaffTrack;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;
use Bitrix\StaffTrackMobile\Internal\Integration\Timeman\FullReportReadiness;
use Bitrix\TimemanMobile\Public\Features\WorkReportsFeature;
use Bitrix\TimemanMobile\Public\Features\WorkReportsWithAiFeature;

/**
 * The rule of the unsent work report of the user of the session: the label of the work reports tab of the
 * check-in and the user counter of the item of the check-in in More, which only this module writes.
 *
 * The conditions are checked in the order of their price, and the first one that fails decides.
 */
class WorkReportCounter
{
	public const CODE = 'check_in_work_report';
	public const SITE_ID = '**';

	protected const LOCK_TIMEOUT_SECONDS = 5;

	private const LOCK_NAME_PREFIX = 'stafftrackmobile_work_report_counter_';

	private ?FullReportReadiness $readiness = null;

	/**
	 * Tells whether timeman is available to the user as a tab of the check-in: the module, its licence and the
	 * rights of the user, the mobile module of timeman and the tool "Work time".
	 *
	 * @return bool
	 */
	public function isTimemanAvailableAsTab(): bool
	{
		return $this->isTimemanAvailable();
	}

	/**
	 * Tells whether the label of the work reports tab is lit: the AI work reports are on in the app, timeman is
	 * available and the report is ready for sending.
	 *
	 * @return bool
	 */
	public function isReportReadyForLabel(): bool
	{
		return
			$this->isWorkReportsEnabled()
			&& $this->isAiMode()
			&& $this->isTimemanAvailable()
			&& $this->isReportReady()
		;
	}

	/**
	 * Tells whether the counter applies to the user of the session at all, whatever the report: the timeman of the
	 * portal announces the written reports, the conditions of the label but the readiness hold, and the user has
	 * the new check-in.
	 *
	 * @return bool
	 */
	public function isApplicable(): bool
	{
		return
			$this->getCurrentUserId() > 0
			&& $this->isNewTimeman()
			&& $this->isWorkReportsEnabled()
			&& $this->isAiMode()
			&& $this->isTimemanAvailable()
			&& $this->isNewCheckIn()
		;
	}

	/**
	 * Returns the value of the counter by the state of now: 1 while the counter applies and the report is ready.
	 *
	 * @return int
	 */
	public function calculate(): int
	{
		return $this->evaluate()['value'];
	}

	/**
	 * Writes the counter of the user of the session by the state of now, under the lock of the user, and only when
	 * the value changes. A failure of the calculation reaches the caller, and nothing is written then.
	 *
	 * @return array{value: int, isApplicable: bool}|null null when the lock of the user was not taken in time,
	 *     nothing is written then
	 */
	public function sync(): ?array
	{
		$userId = $this->getCurrentUserId();
		if ($userId <= 0)
		{
			return $this->evaluate();
		}

		if (!$this->lock($userId))
		{
			return null;
		}

		try
		{
			$state = $this->evaluate();
			$this->write($userId, $state['value']);
		}
		finally
		{
			$this->unlock($userId);
		}

		return $state;
	}

	/**
	 * Puts the counter of the user of the session out without calculating the rule, under the lock of the user.
	 *
	 * @return bool false when the lock of the user was not taken in time, nothing is written then
	 */
	public function clear(): bool
	{
		$userId = $this->getCurrentUserId();
		if ($userId <= 0)
		{
			return true;
		}

		if (!$this->lock($userId))
		{
			return false;
		}

		try
		{
			$this->write($userId, 0);
		}
		finally
		{
			$this->unlock($userId);
		}

		return true;
	}

	protected function isWorkReportsEnabled(): bool
	{
		return Feature::isEnabled(WorkReportsFeature::class);
	}

	protected function isAiMode(): bool
	{
		return Feature::isEnabled(WorkReportsWithAiFeature::class);
	}

	protected function isTimemanAvailable(): bool
	{
		$settingsProvider = $this->getSettingsProvider();

		return
			$settingsProvider->isTimemanAvailable()
			&& Loader::includeModule('timemanmobile')
			&& $settingsProvider->isWorkTimeToolAvailable()
		;
	}

	protected function isReportReady(): bool
	{
		return $this->getReadiness()->isReportReadyForSubmit($this->getCurrentUserId());
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected function isNewTimeman(): bool
	{
		return $this->getReadiness()->isNewTimeman();
	}

	protected function isNewCheckIn(): bool
	{
		return StaffTrack\Feature::isCheckInRolloutEnabled($this->getCurrentUserId());
	}

	protected function getSettingsProvider(): CheckInSettingsProvider
	{
		return new CheckInSettingsProvider();
	}

	protected function writeCounter(int $userId, int $value): void
	{
		\CUserCounter::Set($userId, self::CODE, $value, self::SITE_ID);
	}

	/**
	 * @return array{value: int, isApplicable: bool}
	 */
	private function evaluate(): array
	{
		$isApplicable = $this->isApplicable();

		return [
			'value' => $isApplicable && $this->isReportReady() ? 1 : 0,
			'isApplicable' => $isApplicable,
		];
	}

	/**
	 * Writes the value only when the stored one differs: no row for a dark counter, no pull and no reset of the
	 * cache of the counters for a value that stays.
	 */
	private function write(int $userId, int $value): void
	{
		if ((int)\CUserCounter::GetValue($userId, self::CODE, self::SITE_ID) !== $value)
		{
			$this->writeCounter($userId, $value);
		}
	}

	private function lock(int $userId): bool
	{
		return Application::getConnection()->lock($this->getLockName($userId), static::LOCK_TIMEOUT_SECONDS);
	}

	private function unlock(int $userId): void
	{
		Application::getConnection()->unlock($this->getLockName($userId));
	}

	private function getLockName(int $userId): string
	{
		return self::LOCK_NAME_PREFIX . $userId;
	}

	private function getReadiness(): FullReportReadiness
	{
		return $this->readiness ??= new FullReportReadiness();
	}
}
