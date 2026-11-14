<?php

namespace Bitrix\StaffTrack\Infrastructure\Agent\CheckIn;

use Bitrix\Main\Update\Stepper;
use Bitrix\StaffTrack\Public\Services\CheckInService;

class ManualCountBackfill extends Stepper
{
	public const OPEN_DAYS_RECHECK_DELAY_SEC = 3600;

	protected static $moduleId = 'stafftrack';

	private CheckInService $checkInService;

	public function __construct(?CheckInService $checkInService = null)
	{
		$this->checkInService = $checkInService ?? CheckInService::getInstance();
	}

	public function execute(array &$option): bool
	{
		$afterId = (int)($option['lastId'] ?? 0);
		$cursor = $this->checkInService->backfillManualCounts($afterId);
		if ($cursor === null)
		{
			return self::FINISH_EXECUTION;
		}

		if ($cursor === 0 && $afterId === 0)
		{
			$this->postponeUntilOpenDaysClose();
		}

		$option['lastId'] = $cursor;

		return self::CONTINUE_EXECUTION;
	}

	private function postponeUntilOpenDaysClose(): void
	{
		global $pPERIOD;

		$pPERIOD = self::OPEN_DAYS_RECHECK_DELAY_SEC;
	}
}
