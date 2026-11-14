<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\EventHandlers\BoardStatistics;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Repository\BoardCreationStatisticsRepository;
use Bitrix\Disk\Internal\Service\BoardStatistics\BoardCreationTracker;
use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Throwable;

/**
 * disk:onAfterAddFile is sent after the commit of the file, so the counter is written outside of that
 * transaction and never delays it. A failure of the statistics is logged and never reaches the caller
 * that stored the file.
 */
final class TrackBoardCreationHandler
{
	public static function onAfterAddFile(Event $event): void
	{
		$file = $event->getParameter(0);
		if (!$file instanceof File)
		{
			return;
		}

		try
		{
			(new BoardCreationTracker(new BoardCreationStatisticsRepository()))->track($file);
		}
		catch (Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}
}
