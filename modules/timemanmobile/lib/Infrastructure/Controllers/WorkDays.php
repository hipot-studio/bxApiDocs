<?php

declare(strict_types=1);

namespace Bitrix\TimemanMobile\Infrastructure\Controllers;

use Bitrix\Intranet\Public\Provider\User\AbsenceProvider;
use Bitrix\Intranet\Util;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Mobile\Provider\CommonUserDto;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Timeman\Security\UserPermissionsManager;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\V2\Public\Dto\Record\RecordCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter as RecordFilter;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;
use Bitrix\TimemanMobile\Infrastructure\Controllers\ActionFilters\Attributes\IntranetOnly;
use Bitrix\Timeman\V2\Public\Provider\ReportProvider;
use Bitrix\Timeman\V2\Public\Provider\Params\Report\Filter as ReportFilter;
use Bitrix\Intranet\Settings\Tools\ToolsManager;

final class WorkDays extends Base
{
	private const MONTH_LIMIT = 500;

	// A calendar month is never longer than 31 days, and the extra day absorbs any device time zone
	// shift, so a range the client really built from a month is never capped.
	private const MAX_PERIOD_SECONDS = 32 * 86400;

	/** @var array<int, bool> */
	private array $canReadWorktimeCache = [];

	private function canReadWorktime(int $userId): bool
	{
		return $this->canReadWorktimeCache[$userId]
			??= $this->getAccessManager()->canReadWorktime($userId);
	}

	private function isWorkTimeToolAvailable(): bool
	{
		return Loader::includeModule('intranet')
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('worktime');
	}

	/**
	 * @ajaxAction timemanmobile.WorkDays.getData
	 */
	#[CloseSession]
	public function getDataAction(
		#[PositiveNumber]
		int $userId,
		#[PositiveNumber]
		int $dateFrom,
		#[PositiveNumber]
		int $dateTo,
	): ?array
	{
		if (!Loader::includeModule('timeman'))
		{
			$this->addError(new Error('Module timeman is not installed.'));

			return null;
		}

		$records = $this->getRecords($userId, $dateFrom, $dateTo);

		return [
			'records' => $records->toArray(),
			'reports' => $this->getReports($userId, $records->getIds()),
			'absences' => $this->getAbsences($userId, $dateFrom, $dateTo),
			'user' => $this->getUser($userId),
		];
	}

	/**
	 * @ajaxAction timemanmobile.WorkDays.getMonthRecordCount
	 */
	#[CloseSession]
	public function getMonthRecordCountAction(
		#[PositiveNumber]
		int $dateFrom,
		#[PositiveNumber]
		int $dateTo,
	): ?array
	{
		if (!Loader::includeModule('timeman'))
		{
			$this->addError(new Error('Module timeman is not installed.'));

			return null;
		}

		$userId = (int)CurrentUser::get()->getId();

		if (!$this->canReadWorktime($userId) || !$this->isWorkTimeToolAvailable())
		{
			return ['count' => 0];
		}

		// an inverted range is not a period at all, same reading as FullReportType::resolveByPeriod()
		if ($dateTo < $dateFrom)
		{
			return ['count' => 0];
		}

		// month bounds are resolved by the client in the device time zone, same as the work days
		// screen; the server only caps the length so the cost of the answer stays bounded
		$recordIds = (new RecordProvider())->getRecordIdsForPeriod(
			$userId,
			$dateFrom,
			min($dateTo, $dateFrom + self::MAX_PERIOD_SECONDS),
		);

		return ['count' => count($recordIds)];
	}

	/**
	 * @ajaxAction timemanmobile.WorkDays.getSettings
	 */
	#[CloseSession]
	#[IntranetOnly]
	public function getSettingsAction(): array
	{
		$provider = new SettingsProvider();

		return [
			'isWorkShiftOptionEnabledInSettings' => $provider->isShiftsEnabledInSettings(),
			'canChangeWorkShiftOption' => $provider->canChangeShiftsSetting(),
		];
	}

	/**
	 * @ajaxAction timemanmobile.WorkDays.enableWorkShiftOption
	 */
	#[IntranetOnly]
	public function enableWorkShiftOptionAction(): void
	{
		(new SettingsProvider())->enableShifts();
	}

	/**
	 * @ajaxAction timemanmobile.WorkDays.disableWorkShiftOption
	 */
	#[IntranetOnly]
	public function disableWorkShiftOptionAction(): void
	{
		(new SettingsProvider())->disableShifts();
	}

	private function getUser(int $userId): ?CommonUserDto
	{
		if (!$this->canViewUser($userId))
		{
			return null;
		}

		return UserRepository::getByIds([$userId])[0] ?? null;
	}

	private function getAccessManager(): UserPermissionsManager
	{
		global $USER;

		return DependencyManager::getInstance()->getUserPermissionsManager(
			$USER instanceof \CUser ? $USER : null,
		);
	}

	private function getRecords(int $userId, int $dateFrom, int $dateTo): RecordCollection
	{
		if (!$this->canReadWorktime($userId) || !$this->isWorkTimeToolAvailable())
		{
			return new RecordCollection();
		}

		return (new RecordProvider())->getRecords(
			new ListParams(
				pager: new Pager(limit: self::MONTH_LIMIT),
				filter: new RecordFilter(
					userId: $userId,
					dateFrom: $dateFrom,
					dateTo: $dateTo,
				),
			),
		);
	}

	/**
	 * @param int[] $recordIds
	 */
	private function getReports(int $userId, array $recordIds): array
	{
		if (!$this->canReadWorktime($userId) || !$this->isWorkTimeToolAvailable())
		{
			return [];
		}

		$reports = (new ReportProvider())->getReports(
			$userId,
			new ListParams(
				pager: new Pager(limit: self::MONTH_LIMIT),
				filter: new ReportFilter(
					recordId: $recordIds,
					withAi: true,
				),
			),
		);

		return $reports->toArray();
	}

	private function getAbsences(int $userId, int $dateFrom, int $dateTo): array
	{
		if (!$this->canViewUser($userId))
		{
			return [];
		}

		if (!(
			Loader::includeModule('intranet')
			&& Loader::includeModule('iblock')
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('absence')
		))
		{
			return [];
		}

		$collection = (new AbsenceProvider())->getUserAbsences(
			$userId,
			DateTime::createFromTimestamp($dateFrom),
			DateTime::createFromTimestamp($dateTo),
		);

		if ($collection === null)
		{
			return [];
		}

		$result = [];
		foreach ($collection as $absence)
		{
			$result[] = $absence->toArray();
		}

		return $result;
	}

	private function canViewUser(int $targetUserId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		$currentUserId = (int)CurrentUser::get()->getId();

		if ($currentUserId === $targetUserId)
		{
			return true;
		}

		if (Util::isIntranetUser($currentUserId))
		{
			return true;
		}

		if (Util::isExtranetUser($currentUserId) && Loader::includeModule('extranet'))
		{
			$visibleUserIds = array_merge(
				\CExtranet::getMyGroupsUsersSimple(SITE_ID),
				\CExtranet::GetPublicUsers(),
			);

			return in_array($targetUserId, $visibleUserIds, true);
		}

		return false;
	}
}
