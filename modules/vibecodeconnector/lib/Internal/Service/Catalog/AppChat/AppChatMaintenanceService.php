<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

final class AppChatMaintenanceService
{
	public function __construct(
		private readonly AppChatTeardownService $teardownService = new AppChatTeardownService(),
		private readonly AppChatOrphanCleanupService $orphanCleanupService = new AppChatOrphanCleanupService(),
		private readonly AppChatSyncRecoveryService $syncRecoveryService = new AppChatSyncRecoveryService(),
		private readonly AppChatBackfillService $backfillService = new AppChatBackfillService(),
	) {}

	public function run(): void
	{
		try
		{
			$this->teardownService->applyPendingTeardowns();
		}
		catch (\Throwable $exception)
		{
			\AddMessage2Log(
				'App chat maintenance failed to tear down deactivated chats: ' . $exception->getMessage(),
				'vibecodeconnector',
			);
		}

		try
		{
			$this->orphanCleanupService->run();
		}
		catch (\Throwable $exception)
		{
			\AddMessage2Log(
				'App chat maintenance failed to clean up orphan chats: ' . $exception->getMessage(),
				'vibecodeconnector',
			);
		}

		try
		{
			$this->syncRecoveryService->run();
		}
		catch (\Throwable $exception)
		{
			\AddMessage2Log(
				'App chat maintenance failed to recover pending syncs: ' . $exception->getMessage(),
				'vibecodeconnector',
			);
		}

		try
		{
			$this->backfillService->runSlice();
		}
		catch (\Throwable $exception)
		{
			\AddMessage2Log(
				'App chat maintenance failed to advance backfill: ' . $exception->getMessage(),
				'vibecodeconnector',
			);
		}
	}
}
