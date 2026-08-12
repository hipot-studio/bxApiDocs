<?php

namespace Bitrix\StaffTrack\Infrastructure\Agent\CheckIn;

use Bitrix\StaffTrack\Public\Services\CheckInBacklogService;

class BacklogAggregateAgent
{
	private const CONTINUATION_DELAY = 300;

	public static function run(): string
	{
		self::processAndContinueIfNeeded();

		return 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\BacklogAggregateAgent::run();';
	}

	public static function runContinuation(): string
	{
		$hasMore = self::processAndContinueIfNeeded();

		if ($hasMore)
		{
			return 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\BacklogAggregateAgent::runContinuation();';
		}

		return '';
	}

	private static function processAndContinueIfNeeded(): bool
	{
		$hasMore = false;

		try
		{
			$hasMore = (new CheckInBacklogService())->aggregateStaleCheckIns();

			if ($hasMore)
			{
				self::scheduleContinuation();
			}
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('BacklogAggregateAgent error: ' . $e->getMessage());
		}

		return $hasMore;
	}

	private static function scheduleContinuation(): void
	{
		$agentName = 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\BacklogAggregateAgent::runContinuation();';

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
