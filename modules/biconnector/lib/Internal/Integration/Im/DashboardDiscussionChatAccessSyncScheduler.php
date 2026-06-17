<?php

namespace Bitrix\BIConnector\Internal\Integration\Im;

use Bitrix\BIConnector\Public\Command\DashboardChat\SyncDashboardDiscussionChatAccessCommand;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class DashboardDiscussionChatAccessSyncScheduler
{
	private const REQUESTED_AT_OPTION = '~dashboard_chat_access_sync_requested_at';
	private const SYNCED_AT_OPTION = '~dashboard_chat_access_sync_synced_at';
	private const AGENT_NAME = '\\Bitrix\\BIConnector\\Internal\\Integration\\Im\\DashboardDiscussionChatAccessSyncScheduler::retryAgent();';
	private const AGENT_INTERVAL = 300;
	private const LOCK_KEY = 'biconnector_dashboard_chat_access_sync';

	public function scheduleAll(): void
	{
		$this->markSyncRequested();
		$this->ensureAgentScheduled();

		Application::getInstance()->addBackgroundJob(static function (): void {
			(new self())->runBackgroundFullSync();
		});
	}

	public function scheduleDashboardIds(array $dashboardIds): void
	{
		$dashboardIds = $this->normalizeIds($dashboardIds);
		if (empty($dashboardIds))
		{
			return;
		}

		$this->markSyncRequested();
		$this->ensureAgentScheduled();

		Application::getInstance()->addBackgroundJob(static function () use ($dashboardIds): void {
			(new self())->runBackgroundDashboardSync($dashboardIds);
		});
	}

	public static function retryAgent(): string
	{
		return (new self())->processRetryAgent();
	}

	private function processRetryAgent(): string
	{
		if (!$this->lock())
		{
			return self::AGENT_NAME;
		}

		try
		{
			if (!$this->needsFullSync())
			{
				return '';
			}

			$syncStartedAt = $this->createSyncVersion();
			$syncResult = (new SyncDashboardDiscussionChatAccessCommand())->run();
				if ($syncResult->isSuccess())
				{
					$this->markFullSyncCompleted($syncStartedAt);

					return $this->needsFullSync() ? self::AGENT_NAME : '';
				}
			}
			catch (\Throwable)
			{
			}
			finally
			{
				$this->unlock();
		}

		return self::AGENT_NAME;
	}

	private function runBackgroundFullSync(): void
	{
		if (!$this->lock())
		{
			return;
		}

		try
		{
			$syncStartedAt = $this->createSyncVersion();
			$syncResult = (new SyncDashboardDiscussionChatAccessCommand())->run();
			if ($syncResult->isSuccess())
			{
				$this->markFullSyncCompleted($syncStartedAt);

				return;
			}
		}
		catch (\Throwable)
		{
		}
		finally
		{
			$this->unlock();
		}
	}

	private function runBackgroundDashboardSync(array $dashboardIds): void
	{
		if (!$this->lock())
		{
			return;
		}

		try
		{
			(new SyncDashboardDiscussionChatAccessCommand($dashboardIds))->run();
		}
		catch (\Throwable)
		{
		}
		finally
		{
			$this->unlock();
		}
	}

	private function markSyncRequested(): void
	{
		Option::set('biconnector', self::REQUESTED_AT_OPTION, (string)$this->createSyncVersion());
	}

	private function markFullSyncCompleted(int $syncStartedAt): void
	{
		Option::set('biconnector', self::SYNCED_AT_OPTION, (string)$syncStartedAt);
	}

	private function needsFullSync(): bool
	{
		return $this->getOptionValue(self::REQUESTED_AT_OPTION) > $this->getOptionValue(self::SYNCED_AT_OPTION);
	}

	private function ensureAgentScheduled(): void
	{
		\CAgent::RemoveAgent(self::AGENT_NAME, 'biconnector');
		\CAgent::AddAgent(self::AGENT_NAME, 'biconnector', 'N', self::AGENT_INTERVAL, '', 'Y', '', 100, false, false);
	}

	private function getOptionValue(string $optionName): int
	{
		return max(0, (int)Option::get('biconnector', $optionName, '0'));
	}

	private function createSyncVersion(): int
	{
		return (int)round(microtime(true) * 1000000);
	}

	private function normalizeIds(array $ids): array
	{
		$normalizedIds = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalizedIds[$id] = $id;
			}
		}

		return array_values($normalizedIds);
	}

	private function lock(): bool
	{
		return Application::getConnection()->lock(self::LOCK_KEY, 0);
	}

	private function unlock(): void
	{
		Application::getConnection()->unlock(self::LOCK_KEY);
	}
}
