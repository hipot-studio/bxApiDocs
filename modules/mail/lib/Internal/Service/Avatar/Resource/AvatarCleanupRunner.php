<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Avatar\Resource;

use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

final class AvatarCleanupRunner
{
	private const BATCH_SIZE = 100;

	private \Closure $logException;

	public function __construct(?callable $logException = null)
	{
		$this->logException = $logException === null
			? static function (): void {}
			: \Closure::fromCallable($logException)
		;
	}

	public function run(callable $load, callable $cleanup, int $cursor): int
	{
		$cursor = max(0, $cursor);
		$nextCursor = $cursor;
		try
		{
			$resourceIds = $load($cursor, self::BATCH_SIZE, $nextCursor);
		}
		catch (\Throwable $exception)
		{
			$this->log($exception);

			return $cursor;
		}

		$processed = 0;
		foreach ($resourceIds as $resourceId)
		{
			if ($processed >= self::BATCH_SIZE)
			{
				break;
			}
			$processed++;
			try
			{
				$result = $cleanup((int)$resourceId);
				if (!$result instanceof Result || !$result->isSuccess())
				{
					$messages = $result instanceof Result ? $result->getErrorMessages() : [];
					throw new SystemException(sprintf(
						'Avatar resource %d cleanup failed: %s',
						$resourceId,
						implode('; ', $messages),
					));
				}
			}
			catch (\Throwable $exception)
			{
				$this->log($exception);
			}
		}

		return $nextCursor;
	}

	private function log(\Throwable $exception): void
	{
		try
		{
			($this->logException)($exception);
		}
		catch (\Throwable)
		{
		}
	}
}
