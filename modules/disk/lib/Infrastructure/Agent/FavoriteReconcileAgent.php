<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent;

use Bitrix\Disk\Internal\Repository\FavoriteMarkRepository;
use Bitrix\Disk\Internal\Service\Favorite\FavoriteAccessReconciler;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Config\Option;
use Psr\Log\LoggerInterface;

/**
 * The safety net of the favorite marks: goes round all the marks by their id, a portion after a portion within the
 * budget of a step, and drops those whose object is gone or no longer read by their user. The paths of access
 * that leave no reconciliation behind are caught here.
 */
class FavoriteReconcileAgent extends BaseDiskAgent
{
	public const CURSOR_OPTION = 'favorites_reconcile_cursor';
	protected const CHUNK_SIZE = 500;
	protected const RUN_TIME_LIMIT_SECONDS = 5;

	private FavoriteMarkRepository $repository;
	private FavoriteAccessReconciler $reconciler;
	private LoggerInterface $logger;
	private int $chunkSize;

	public function __construct(
		?FavoriteMarkRepository $repository = null,
		?FavoriteAccessReconciler $reconciler = null,
		?LoggerInterface $logger = null,
		?int $chunkSize = null,
	)
	{
		$this->repository = $repository ?? new FavoriteMarkRepository();
		$this->reconciler = $reconciler ?? new FavoriteAccessReconciler($this->repository);
		$this->logger = $logger ?? LoggerFactory::create(feature: 'favorites reconcile agent');
		$this->chunkSize = max(1, $chunkSize ?? static::CHUNK_SIZE);
	}

	protected function runInternal(): RunResult
	{
		try
		{
			$this->reconcileWithinBudget();
		}
		catch (\Throwable $exception)
		{
			$this->logger->error('Favorite marks reconciliation failed: {message}', [
				'message' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}

		return new RunResult(true);
	}

	protected function now(): float
	{
		return microtime(true);
	}

	private function reconcileWithinBudget(): void
	{
		$deadline = $this->now() + static::RUN_TIME_LIMIT_SECONDS;
		$lastId = $this->readCursor();
		do
		{
			$marks = $this->repository->findChunkAfter($lastId, $this->chunkSize);
			if ($marks !== [])
			{
				$this->reconciler->reconcile($marks);
				$lastId = end($marks)->id;
			}

			if (count($marks) < $this->chunkSize)
			{
				// The pass has reached the end: the next one starts from the beginning.
				$lastId = 0;

				break;
			}
		}
		while ($this->now() < $deadline);

		$this->writeCursor($lastId);
	}

	private function readCursor(): int
	{
		return max(0, (int)Option::get(static::$moduleId, self::CURSOR_OPTION, '0'));
	}

	private function writeCursor(int $lastId): void
	{
		Option::set(static::$moduleId, self::CURSOR_OPTION, (string)$lastId);
	}
}
