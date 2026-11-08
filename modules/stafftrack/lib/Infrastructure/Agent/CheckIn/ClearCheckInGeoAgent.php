<?php

namespace Bitrix\StaffTrack\Infrastructure\Agent\CheckIn;

use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Integration\Im\MessageService;
use Bitrix\StaffTrack\Internal\Repository\CheckInByDayRepository;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Service\CheckIn\CheckInGeoCleanupService;

class ClearCheckInGeoAgent
{
	private const EXECUTION_TIME_LIMIT = 120;
	private const CONTINUATION_DELAY = 300;

	public static function run(): string
	{
		// Delta-by-date cleanup: progress is carried forward in the cleaned_until boundary
		// and is never reset. Each run processes the band (cleaned_until; threshold] and
		// advances cleaned_until once the band is drained, so newly aged records are picked
		// up on subsequent days without re-walking everything.
		$hasMore = self::processCleanup();

		if ($hasMore)
		{
			self::scheduleContinuation();
		}

		return self::class . '::run();';
	}

	public static function runContinuation(): string
	{
		$hasMore = self::processCleanup();

		if ($hasMore)
		{
			return self::class . '::runContinuation();';
		}

		return '';
	}

	/**
	 * @return bool
	 */
	private static function processCleanup(): bool
	{
		if (!Feature::isGeoRetentionLimited())
		{
			return false;
		}

		$hasMore = false;

		try
		{
			$service = new CheckInGeoCleanupService(
				new CheckInRepository(),
				new CheckInByDayRepository(),
				new MessageService(0),
			);

			$hasMore = $service->run(self::EXECUTION_TIME_LIMIT);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('ClearCheckInGeoAgent error: ' . $e->getMessage(), 'stafftrack');
		}

		return $hasMore;
	}

	private static function scheduleContinuation(): void
	{
		$agentName = self::class . '::runContinuation();';

		\CAgent::RemoveAgent($agentName, 'stafftrack');
		\CAgent::AddAgent(
			$agentName,
			'stafftrack',
			'N',
			self::CONTINUATION_DELAY,
			'',
			'Y',
			ConvertTimeStamp(time() + self::CONTINUATION_DELAY, 'FULL'),
		);
	}
}