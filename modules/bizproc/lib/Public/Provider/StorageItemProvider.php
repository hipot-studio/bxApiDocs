<?php

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewFreshness;
use Bitrix\Bizproc\Internal\Entity\StorageItem;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Public\Command\DataView\RecomputeDataViewCommand;
use Bitrix\Bizproc\Public\Command\DataView\RecomputeDataViewCommandHandler;
use Bitrix\Bizproc\Public\DataView\Exception\RecomputeInProgressException;
use Bitrix\Bizproc\Public\Feature\DataView\DataViewFlag;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Provider\Params\GridParams;

class StorageItemProvider
{
	private const RECOMPUTE_COOLDOWN_SECONDS = 60;

	/**
	 * A broken view retries far less often than a transient failure: its recompute failed
	 * deterministically, so re-probing every minute would just repeat the doomed full scan. One
	 * background probe per this window is enough to let the view heal once its cause is gone.
	 */
	private const BROKEN_RETRY_COOLDOWN_SECONDS = 3600;

	private const RECOMPUTE_COOLDOWN_DIR = '/bizproc/dataview/recompute/';

	/** @var array<int, DataViewFreshness|null> per-hit lookup cache, negative result included */
	private static array $freshnessByStorageTypeId = [];

	/** @var array<int, true> views with a background recompute already queued in this hit */
	private static array $recomputeScheduled = [];

	private int $storageTypeId;
	private StorageItemRepositoryInterface $repository;
	private bool $freshnessEnsured = false;

	public function __construct(int $storageTypeId)
	{
		$this->storageTypeId = $storageTypeId;
		$this->repository = Container::getStorageItemRepository();
	}

	public function getById(int $id, array $select = ['*']): ?StorageItem\StorageItem
	{
		$this->ensureFresh();

		return $this->repository->getItem($this->storageTypeId, $id, $select);
	}

	public function getItems(array $parameters = []): ?StorageItem\StorageItemCollection
	{
		$this->ensureFresh();

		return $this->repository->getItems($this->storageTypeId, $parameters);
	}

	public function exists(int $id): bool
	{
		return $this->repository->exists($id);
	}

	public function getList(GridParams $gridParams): ?StorageItem\StorageItemCollection
	{
		$this->ensureFresh();

		return $this->repository->getList(
			storageTypeId: $this->storageTypeId,
			limit: $gridParams->getLimit(),
			offset: $gridParams->getOffset(),
			filter: $gridParams->filter,
			sort: $gridParams->getSort(),
			select: $gridParams->getSelect(),
		);
	}

	public function getCount(array $filter = []): int
	{
		$this->ensureFresh();

		return $this->repository->getCount($this->storageTypeId, $filter);
	}

	private function ensureFresh(): void
	{
		if ($this->freshnessEnsured)
		{
			return;
		}
		$this->freshnessEnsured = true;

		if (!Feature::isEnabled(DataViewFlag::class))
		{
			return;
		}

		$freshness = $this->resolveFreshness();
		if ($freshness === null)
		{
			return;
		}

		// A broken view failed its last recompute deterministically (source larger than the limit,
		// source removed, unsupported definition, inactive actor). The cause can disappear without a
		// manual re-save — the source shrinks or returns, a newer version supports the definition, the
		// actor is reactivated — so the view is re-probed on a long backoff instead of staying broken
		// forever. The probe runs only in the background and never on the synchronous read path, so a
		// still-doomed recompute keeps serving the last slice without a per-read full scan.
		if ($freshness->isBroken())
		{
			$this->probeBrokenView();

			return;
		}

		// A transient failure (locked source, infrastructure hiccup) does not mark the view broken;
		// the cooldown keeps every read within the window from launching another recompute.
		if ($this->isRecomputeOnCooldown())
		{
			return;
		}

		$this->recompute();
	}

	private function scheduleBackgroundRecompute(): void
	{
		if (isset(self::$recomputeScheduled[$this->storageTypeId]))
		{
			return;
		}
		self::$recomputeScheduled[$this->storageTypeId] = true;

		Application::getInstance()->addBackgroundJob(function (): void {
			$this->recompute();
		});
	}

	private function probeBrokenView(): void
	{
		if ($this->isOnCooldown($this->brokenRetryCooldownKey(), self::BROKEN_RETRY_COOLDOWN_SECONDS))
		{
			return;
		}
		$this->startCooldown($this->brokenRetryCooldownKey(), self::BROKEN_RETRY_COOLDOWN_SECONDS);

		$this->scheduleBackgroundRecompute();
	}

	/**
	 * Data views are the exception, not the rule: most storages have no view at all. The lookup
	 * is therefore cached for the hit, negative result included, so repeated reads of an ordinary
	 * storage do not re-query the view table.
	 */
	private function resolveFreshness(): ?DataViewFreshness
	{
		if (array_key_exists($this->storageTypeId, self::$freshnessByStorageTypeId))
		{
			return self::$freshnessByStorageTypeId[$this->storageTypeId];
		}

		return self::$freshnessByStorageTypeId[$this->storageTypeId]
			= Container::getDataViewRepository()?->getFreshness($this->storageTypeId);
	}

	/**
	 * Runs synchronously on a cold start and as the body of the queued background job; both
	 * paths share the cooldown-on-failure and freshness cache invalidation.
	 */
	private function recompute(): void
	{
		try
		{
			(new RecomputeDataViewCommandHandler())(new RecomputeDataViewCommand($this->storageTypeId));
		}
		catch (RecomputeInProgressException)
		{
			// Another process is already recomputing this view; its result lands shortly.
		}
		catch (\Throwable $exception)
		{
			$this->startRecomputeCooldown();
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		finally
		{
			unset(self::$freshnessByStorageTypeId[$this->storageTypeId]);
		}
	}

	private function isRecomputeOnCooldown(): bool
	{
		return $this->isOnCooldown($this->recomputeCooldownKey(), self::RECOMPUTE_COOLDOWN_SECONDS);
	}

	private function startRecomputeCooldown(): void
	{
		$this->startCooldown($this->recomputeCooldownKey(), self::RECOMPUTE_COOLDOWN_SECONDS);
	}

	private function isOnCooldown(string $key, int $ttl): bool
	{
		return Cache::createInstance()->initCache($ttl, $key, $this->cooldownDir($key));
	}

	private function startCooldown(string $key, int $ttl): void
	{
		$cache = Cache::createInstance();
		if ($cache->initCache($ttl, $key, $this->cooldownDir($key)))
		{
			return;
		}

		if ($cache->startDataCache())
		{
			$cache->endDataCache(true);
		}
	}

	private function recomputeCooldownKey(): string
	{
		return 'recompute_' . $this->storageTypeId;
	}

	private function brokenRetryCooldownKey(): string
	{
		return 'broken_retry_' . $this->storageTypeId;
	}

	private function cooldownDir(string $key): string
	{
		return self::RECOMPUTE_COOLDOWN_DIR
			. substr(md5($key), 2, 2) . '/' . $this->storageTypeId . '/';
	}
}
