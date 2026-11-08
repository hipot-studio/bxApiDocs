<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Command\ChartAnalysis;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

/**
 * Starts a BitrixGPT chat about one chart of a report from the bxChartAnalyze message of the iframe.
 */
class LaunchChartAnalysisCommand extends AbstractCommand
{
	/**
	 * @param int $dashboardId Id of the report in Bitrix24, not in Superset.
	 * @param string $payloadJson The bxChartAnalyze message as the iframe sent it.
	 * @param int $chatId Chat of an earlier analysis on the page to continue; 0 starts a new chat.
	 */
	public function __construct(
		public readonly int $dashboardId,
		public readonly string $payloadJson,
		public readonly int $userId,
		public readonly int $chatId = 0,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new LaunchChartAnalysisCommandHandler())($this);
		}
		catch (\Exception $exception)
		{
			return (new ChartAnalysisLaunchResult())->refuse(
				ChartAnalysisLaunchResult::REFUSAL_CHAT_FAILED,
				$exception->getMessage(),
			);
		}
	}
}
