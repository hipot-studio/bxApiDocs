<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest\EventHandler;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\OriginTokenReader;
use Bitrix\Vibecodeconnector\Internal\Service\Rest\AccessGrantService;

class AccessGrantHandler
{
	public static function onRestAccessGrant(Event $event): ?EventResult
	{
		try
		{
			$originToken = (new OriginTokenReader())->read();
			if ($originToken === '')
			{
				return null;
			}

			$service = ServiceLocator::getInstance()->get(AccessGrantService::class);
			if (!$service->isGranted($originToken))
			{
				return null;
			}

			return new EventResult(EventResult::SUCCESS);
		}
		catch (\Throwable $exception)
		{
			static::logSafely($exception);

			return null;
		}
	}

	protected static function logSafely(\Throwable $exception): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog(
				$exception,
				ExceptionHandlerLog::CAUGHT_EXCEPTION,
			);
		}
		catch (\Throwable)
		{

		}
	}
}
