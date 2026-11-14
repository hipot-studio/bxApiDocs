<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Service\Logger\ExceptionToContextConverter;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Error;
use Throwable;

/**
 * Maps an unexpected exception to a safe wire-level error: the raw message
 * (driver errors, table/column names) goes to the log only, never to the
 * client envelope.
 */
final class UnexpectedErrorReporter
{
	public const CODE = 'DISK_DOCKED_INTERNAL';

	public static function report(Throwable $exception, string $operation): Error
	{
		LoggerFactory::create(feature: 'docked upload')->error(
			'docked unexpected exception',
			['operation' => $operation] + ExceptionToContextConverter::convert($exception),
		);

		return new Error('Internal error', self::CODE);
	}
}
