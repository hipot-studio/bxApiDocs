<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Main\DB\Connection;
use Bitrix\Main\Result;

final class AuditCommitBoundary
{
	private array $callbacks = [];
	private bool $finished = false;

	public function __construct(private readonly Connection $connection)
	{
	}

	public function getConnection(): Connection
	{
		return $this->connection;
	}

	public function addAfterConfirmedCommit(callable $callback): void
	{
		if (!$this->finished)
		{
			$this->callbacks[] = $callback;
		}
	}

	public function confirmCommitted(): void
	{
		if ($this->finished)
		{
			return;
		}
		$callbacks = $this->callbacks;
		$this->discard();
		foreach ($callbacks as $callback)
		{
			AuditCaptureService::runFailOpen(static function () use ($callback): Result {
				$callback();

				return new Result();
			});
		}
	}

	public function discard(): void
	{
		$this->finished = true;
		$this->callbacks = [];
	}
}
