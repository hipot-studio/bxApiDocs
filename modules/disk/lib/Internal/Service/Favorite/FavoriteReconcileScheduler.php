<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Favorite;

use Bitrix\Disk\Internal\Repository\FavoriteMarkRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Application;
use Psr\Log\LoggerInterface;

/**
 * Collects within a hit the scopes whose access has changed and reconciles their favorite marks once, after the
 * answer: the edit of the access is never held up by the marks.
 */
final class FavoriteReconcileScheduler
{
	/**
	 * Below the recalculation of simple rights the edit queues (@see \Bitrix\Disk\RightsManager), so the
	 * reconciliation reads the access the edit has left.
	 */
	private const JOB_PRIORITY = Application::JOB_PRIORITY_LOW;

	private static ?self $instance = null;

	/** @var array<int, true> */
	private array $subtreeRootIds = [];
	/** @var array<string, array{int, int}> */
	private array $userStorages = [];
	private bool $isJobQueued = false;

	public function __construct(
		private readonly FavoriteMarkRepository $repository = new FavoriteMarkRepository(),
		private readonly ?FavoriteAccessReconciler $reconciler = null,
		private readonly ?LoggerInterface $logger = null,
	)
	{
	}

	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	public function scheduleSubtree(int $objectId): void
	{
		if ($objectId <= 0)
		{
			return;
		}

		$this->subtreeRootIds[$objectId] = true;
		$this->queueJob();
	}

	public function scheduleUserInStorage(int $userId, int $storageId): void
	{
		if ($userId <= 0 || $storageId <= 0)
		{
			return;
		}

		$this->userStorages["{$userId}_{$storageId}"] = [$userId, $storageId];
		$this->queueJob();
	}

	/**
	 * The background job of the hit. Not to be called directly.
	 */
	public function runScheduled(): void
	{
		$subtreeRootIds = array_keys($this->subtreeRootIds);
		$userStorages = $this->userStorages;
		$this->subtreeRootIds = [];
		$this->userStorages = [];
		$this->isJobQueued = false;

		$reconciler = $this->reconciler ?? new FavoriteAccessReconciler($this->repository);
		foreach ($subtreeRootIds as $objectId)
		{
			$this->reconcileSafely(
				fn () => $reconciler->reconcile($this->repository->findByObjectSubtree($objectId)),
				['objectId' => $objectId],
			);
		}
		foreach ($userStorages as [$userId, $storageId])
		{
			$this->reconcileSafely(
				fn () => $reconciler->reconcile($this->repository->findByUserInStorage($userId, $storageId)),
				['userId' => $userId, 'storageId' => $storageId],
			);
		}
	}

	private function queueJob(): void
	{
		if ($this->isJobQueued)
		{
			return;
		}

		$this->isJobQueued = true;
		Application::getInstance()->addBackgroundJob([$this, 'runScheduled'], [], self::JOB_PRIORITY);
	}

	private function reconcileSafely(callable $reconcile, array $scope): void
	{
		try
		{
			$reconcile();
		}
		catch (\Throwable $exception)
		{
			($this->logger ?? LoggerFactory::create(feature: 'favorites'))->error(
				'Favorite marks were not reconciled: {message}',
				['scope' => $scope, 'message' => $exception->getMessage(), 'exception' => $exception],
			);
		}
	}
}
