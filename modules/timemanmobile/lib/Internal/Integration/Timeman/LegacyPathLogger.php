<?php

namespace Bitrix\TimemanMobile\Internal\Integration\Timeman;

use Bitrix\Main\Diag\EventLogger;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Logger of a legacy side effect that failed after the row was already written. Such a failure leaves no
 * other trace, so it must be written without being asked for: the registry is not consulted, and a portal
 * that configures no sink for the id gets the record in the event log.
 */
final class LegacyPathLogger
{
	public static function create(string $loggerId, string $auditType): LoggerInterface
	{
		$logger = (new LoggerFactory(alwaysReturnLogger: false))->createById(
			$loggerId,
			isCheckEnabledFromRegistry: false,
			returnDefaultLoggerIfNotExists: false,
		);

		return $logger ?? new EventLogger('timemanmobile', $auditType);
	}
}
