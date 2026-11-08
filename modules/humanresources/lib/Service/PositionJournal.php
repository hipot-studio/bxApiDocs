<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Service;

use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * What the write points of the structure know about the position journal; the journal itself lives in Integration.
 */
abstract class PositionJournal
{
	public const LOGGER_ID = 'humanresources.structurePositionJournal';

	abstract public function record(string $eventName, array $eventData): void;

	/**
	 * For the write points of the structure: a failed journal must not break the write itself.
	 */
	public static function logFailure(string $writePoint, \Throwable $exception): void
	{
		self::logger()->error(
			'{writePoint}: position journal failed: {message}',
			['writePoint' => $writePoint, 'message' => $exception->getMessage(), 'exception' => $exception],
		);
	}

	protected static function logger(): LoggerInterface
	{
		return (new LoggerFactory())->createById(self::LOGGER_ID);
	}
}
