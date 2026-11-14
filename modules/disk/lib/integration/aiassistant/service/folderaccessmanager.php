<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Disk\Folder;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Security\SecurityContext;

final class FolderAccessManager
{
	private const TASK_RANKS = [
		RightsManager::TASK_READ => 1,
		RightsManager::TASK_EDIT => 2,
		RightsManager::TASK_FULL => 3,
	];

	public function __construct(
		private readonly FolderSharingGateway $gateway = new SharingFolderAccessGateway(),
		private readonly FolderSessionTerminationScheduler $sessionTerminationScheduler =
			new LegacyFolderSessionTerminationScheduler(),
	)
	{
	}

	public function manage(
		Folder $folder,
		SecurityContext $securityContext,
		int $userId,
		string $action,
		string $accessCode,
		?string $taskName,
		?FolderSessionTerminationPlan &$terminationPlan = null,
		bool &$terminationScheduled = false,
	): array
	{
		$sharingEntity = preg_replace('/^(SG\d+)_K$/', '$1', $accessCode);
		$sharing = $this->gateway->findRootSharing($folder, $sharingEntity);

		if ($action === 'grant')
		{
			if ($taskName === null || trim($taskName) === '')
			{
				throw new McpException('taskName is required for grant.');
			}

			if ($sharing === null)
			{
				if (!$this->gateway->add($folder, $userId, $sharingEntity, $taskName))
				{
					throw new McpException("Failed to grant access for {$accessCode}.");
				}

				return $this->buildResult($accessCode, true);
			}

			if ($sharing['taskName'] === $taskName)
			{
				return $this->buildResult($accessCode, false);
			}

			$isDowngrade = $this->isDowngrade($sharing['taskName'], $taskName);
			$preparedPlan = $isDowngrade
				? $this->getTerminationPlan($folder, $securityContext, $terminationPlan)
				: null
			;

			if (!$this->gateway->update($folder, $sharing, $taskName))
			{
				throw new McpException("Failed to change access level for {$accessCode}.");
			}

			if ($preparedPlan !== null)
			{
				$this->scheduleTermination($preparedPlan, $terminationScheduled);
			}

			return $this->buildResult($accessCode, true);
		}

		if ($action === 'revoke')
		{
			if ($sharing === null)
			{
				return $this->buildResult($accessCode, false);
			}

			$preparedPlan = $this->getTerminationPlan($folder, $securityContext, $terminationPlan);

			if (!$this->gateway->delete($sharing['id'], $userId))
			{
				throw new McpException("Failed to revoke access for {$accessCode}.");
			}

			$this->scheduleTermination($preparedPlan, $terminationScheduled);

			return $this->buildResult($accessCode, true);
		}

		throw new McpException("Unknown access action '{$action}'.");
	}

	private function isDowngrade(string $currentTaskName, string $newTaskName): bool
	{
		$currentRank = self::TASK_RANKS[$currentTaskName] ?? null;
		$newRank = self::TASK_RANKS[$newTaskName] ?? null;

		return $currentRank !== null && $newRank !== null && $newRank < $currentRank;
	}

	private function getTerminationPlan(
		Folder $folder,
		SecurityContext $securityContext,
		?FolderSessionTerminationPlan &$terminationPlan,
	): FolderSessionTerminationPlan
	{
		return $terminationPlan ??= $this->sessionTerminationScheduler->prepare($folder, $securityContext);
	}

	private function scheduleTermination(
		FolderSessionTerminationPlan $terminationPlan,
		bool &$terminationScheduled,
	): void
	{
		if ($terminationScheduled)
		{
			return;
		}

		$this->sessionTerminationScheduler->schedule($terminationPlan);
		$terminationScheduled = true;
	}

	private function buildResult(string $accessCode, bool $changed): array
	{
		return [
			'accessCode' => $accessCode,
			'changed' => $changed,
		];
	}
}
