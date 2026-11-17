<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;

class SafeEventLog extends \CEventLog
{
	public static function write(string $level, string $auditType, string $message): void
	{
		// CEventLog::Add replaces caller metadata with the raw request, including bearer public links.
		$fields = [
			'SEVERITY' => strtoupper($level),
			'AUDIT_TYPE_ID' => $auditType,
			'MODULE_ID' => 'disk',
			'ITEM_ID' => 'UNKNOWN',
			'REMOTE_ADDR' => '',
			'USER_AGENT' => '',
			'REQUEST_URI' => '',
			'SITE_ID' => null,
			'USER_ID' => null,
			'GUEST_ID' => null,
			'DESCRIPTION' => $message,
		];
		$logFields = [
			'severity' => $fields['SEVERITY'],
			'auditTypeId' => $auditType,
			'moduleId' => 'disk',
			'itemId' => 'UNKNOWN',
			'remoteAddr' => '',
			'userAgent' => null,
			'requestUri' => '',
			'siteId' => null,
			'userId' => null,
			'guestId' => null,
			'description' => $message,
			'timestamp' => date('Y-m-d H:i:s'),
			'host' => null,
		];
		// Reuse configured event-log exports without writeLogs() adding the raw HTTP_HOST.
		static::getSysLogger()?->log($level, '', $logFields);
		static::getFileLogger()?->log($level, '', $logFields);
		try
		{
			$pool = Application::getInstance()->getConnectionPool();
			$connection = $pool->getConnection();
			$fields['TIMESTAMP_X'] = new SqlExpression($connection->getSqlHelper()->getCurrentDateTimeFunction());
			$pool->ignoreDml(true);
			try
			{
				$connection->add('b_event_log', $fields);
			}
			finally
			{
				$pool->ignoreDml(false);
			}
		}
		catch (\Throwable)
		{
			// Legacy CDatabase errors may send the raw request by email; diagnostics must stay best effort.
		}
	}
}
