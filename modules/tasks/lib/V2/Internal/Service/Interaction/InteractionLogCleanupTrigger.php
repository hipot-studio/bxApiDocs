<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Interaction;

use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\V2\Infrastructure\Agent\TrimUserInteractionLog;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RankingConfig;
use Throwable;

/**
 * Schedules the per-user journal trim when the read path sees a full interaction
 * window. Registration is throttled per user through short-lived persistent
 * storage, so repeated selector opens register the agent at most once per window.
 */
final class InteractionLogCleanupTrigger
{
	private const THROTTLE_KEY_PREFIX = 'tasks.ranking.trim_trigger.';

	private readonly StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * Called when scoring read a full events-limit window, i.e. the user is at or
	 * above the storage cap. Best-effort: failures never reach the read path.
	 */
	public function onFullWindowRead(int $userId): void
	{
		if ($userId <= 0 || !RankingConfig::isEnabled())
		{
			return;
		}

		try
		{
			$key = self::THROTTLE_KEY_PREFIX . $userId;
			if ($this->storage->get($key) !== null)
			{
				return;
			}

			// Marker only after a confirmed registration: set first, a failed AddAgent
			// would block the next attempt for the whole window.
			if (!TrimUserInteractionLog::add($userId))
			{
				return;
			}

			$this->storage->set($key, ['at' => time()], RankingConfig::getTrimTriggerWindow());
		}
		catch (Throwable)
		{
			// Cleanup scheduling is best-effort and must never surface to the user.
		}
	}
}
