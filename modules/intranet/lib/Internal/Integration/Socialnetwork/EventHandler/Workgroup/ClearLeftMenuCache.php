<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork\EventHandler\Workgroup;

use Bitrix\Intranet\Internal\Integration\Socialnetwork\FeatureProvider;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\ProjectPredicateService;
use Bitrix\Intranet\Internal\Service\LeftMenu\GroupFavoritesService;
use Bitrix\Intranet\Internal\Service\LeftMenu\LeftMenuCacheInvalidator;
use Bitrix\Main\Application;
use Bitrix\Main\Event;

class ClearLeftMenuCache
{
	private const PENDING_BATCH_SIZE = 100;

	private static array $pendingPairs = [];
	private static ?self $pendingProcessor = null;

	public function __construct(
		private readonly ProjectPredicateService $predicate = new ProjectPredicateService(),
		private readonly GroupFavoritesService $favorites = new GroupFavoritesService(),
		private readonly LeftMenuCacheInvalidator $cacheInvalidator = new LeftMenuCacheInvalidator(),
		private readonly FeatureProvider $featureProvider = new FeatureProvider(),
	)
	{
	}

	public static function onAfterUserToGroupDelete(Event $event): void
	{
		(new static())->clear($event);
	}

	public static function onUserToGroupAdd(int $relationId, array $data): void
	{
		(new static())->clearRelation($data);
	}

	public static function onUserToGroupUpdate(int $relationId, array $changedData, array $oldData): void
	{
		(new static())->clearUpdatedRelation($changedData, $oldData);
	}

	public function clear(Event $event): void
	{
		$this->clearRelation([
			'GROUP_ID' => $event->getParameter('GROUP_ID'),
			'USER_ID' => $event->getParameter('USER_ID'),
		]);
	}

	public function clearRelation(array $data): void
	{
		try
		{
			$this->scheduleCacheCleanup(
				(int)($data['GROUP_ID'] ?? 0),
				(int)($data['USER_ID'] ?? 0),
			);
		}
		catch (\Throwable $exception)
		{
			$this->writeToLog($exception);
		}
	}

	public function clearUpdatedRelation(array $changedData, array $oldData): void
	{
		$roleChanged = array_key_exists('ROLE', $changedData)
			&& $changedData['ROLE'] !== ($oldData['ROLE'] ?? null);
		$groupChanged = array_key_exists('GROUP_ID', $changedData)
			&& (int)$changedData['GROUP_ID'] !== (int)($oldData['GROUP_ID'] ?? 0);
		$userChanged = array_key_exists('USER_ID', $changedData)
			&& (int)$changedData['USER_ID'] !== (int)($oldData['USER_ID'] ?? 0);

		if (!$roleChanged && !$groupChanged && !$userChanged)
		{
			return;
		}

		if ($groupChanged || $userChanged)
		{
			$this->clearRelation($oldData);
		}

		$this->clearRelation(array_merge($oldData, $changedData));
	}

	protected function writeToLog(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}

	protected function addBackgroundJob(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}

	private function scheduleCacheCleanup(int $groupId, int $userId): void
	{
		if ($userId <= 0 || $groupId <= 0 || !$this->featureProvider->isNewProjectsOn())
		{
			return;
		}

		$key = $groupId . ':' . $userId;
		if (isset(self::$pendingPairs[$key]))
		{
			return;
		}
		self::$pendingPairs[$key] = compact('groupId', 'userId');
		$this->schedulePendingProcessor();
	}

	private function schedulePendingProcessor(): void
	{
		if (self::$pendingProcessor !== null)
		{
			return;
		}

		self::$pendingProcessor = $this;
		try
		{
			$this->addBackgroundJob(static function (): void {
				self::flushPendingPairs();
			});
		}
		catch (\Throwable $exception)
		{
			$this->writeToLog($exception);
			self::flushPendingPairs();
		}
	}

	private static function flushPendingPairs(): void
	{
		if (self::$pendingProcessor === null)
		{
			self::resetPendingState();

			return;
		}

		while (self::$pendingPairs !== [])
		{
			$pairs = [];
			foreach (array_keys(self::$pendingPairs) as $key)
			{
				$pairs[$key] = self::$pendingPairs[$key];
				if (count($pairs) === self::PENDING_BATCH_SIZE)
				{
					self::processPendingPairBatch($pairs);
					$pairs = [];
				}
			}

			if ($pairs !== [])
			{
				self::processPendingPairBatch($pairs);
			}
		}

		self::resetPendingState();
	}

	private static function processPendingPairBatch(array $pairs): void
	{
		$processor = self::$pendingProcessor;

		try
		{
			$processor->invalidatePinnedUsers($pairs);
		}
		catch (\Throwable $exception)
		{
			$processor->writeToLog($exception);
			$processor->invalidateUsers(array_column($pairs, 'userId'));
		}

		foreach (array_keys($pairs) as $key)
		{
			unset(self::$pendingPairs[$key]);
		}
	}

	private static function resetPendingState(): void
	{
		self::$pendingPairs = [];
		self::$pendingProcessor = null;
	}

	private function invalidatePinnedUsers(array $pairs): void
	{
		if (!$this->featureProvider->isNewProjectsOn())
		{
			return;
		}

		$groupIds = array_values(array_unique(array_column($pairs, 'groupId')));
		$this->predicate->preloadGroupTypes(...$groupIds);

		$newProjectPairs = [];
		$groupStates = [];
		foreach ($pairs as $pair)
		{
			$groupId = $pair['groupId'];
			$groupStates[$groupId] ??= $this->predicate->isNewProject($groupId);
			if ($groupStates[$groupId])
			{
				$newProjectPairs[] = $pair;
			}
		}

		$this->invalidateUsers(
			array_column($this->favorites->filterFavoritePairs($newProjectPairs), 'userId'),
		);
	}

	private function invalidateUsers(array $userIds): void
	{
		foreach (array_unique($userIds) as $userId)
		{
			try
			{
				$this->cacheInvalidator->invalidateUser($userId);
			}
			catch (\Throwable $exception)
			{
				$this->writeToLog($exception);
			}
		}
	}
}
