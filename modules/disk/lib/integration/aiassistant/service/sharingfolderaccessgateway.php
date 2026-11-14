<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Sharing;

final class SharingFolderAccessGateway implements FolderSharingGateway
{
	public function findRootSharing(Folder $folder, string $sharingEntity): ?array
	{
		$row = Sharing::getList([
			'select' => ['ID', 'TASK_NAME', 'TYPE'],
			'filter' => [
				'REAL_OBJECT_ID' => $folder->getRealObjectId(),
				'REAL_STORAGE_ID' => $folder->getRealObject()->getStorageId(),
				'!=STATUS' => SharingTable::STATUS_IS_DECLINED,
				'PARENT_ID' => null,
				'TO_ENTITY' => $sharingEntity,
			],
		])->fetch();

		if (!$row)
		{
			return null;
		}

		return [
			'id' => (int)$row['ID'],
			'taskName' => (string)$row['TASK_NAME'],
			'type' => (int)$row['TYPE'],
		];
	}

	public function add(Folder $folder, int $userId, string $sharingEntity, string $taskName): bool
	{
		$errorCollection = new ErrorCollection();
		$sharings = Sharing::addToManyEntities(
			[
				'FROM_ENTITY' => Sharing::CODE_USER . $userId,
				'REAL_OBJECT' => $folder,
				'CREATED_BY' => $userId,
				'CAN_FORWARD' => false,
			],
			[$sharingEntity => $taskName],
			$errorCollection,
		);

		if (is_array($sharings) && isset($sharings[$sharingEntity]))
		{
			return true;
		}

		throw new McpException($this->buildSharingErrorMessage($sharingEntity, $errorCollection));
	}

	public function update(Folder $folder, array $sharing, string $taskName): bool
	{
		$sharingModel = Sharing::getModelList([
			'filter' => ['ID' => $sharing['id']],
		])[0] ?? null;
		if ($sharingModel === null)
		{
			throw new McpException("Sharing {$sharing['id']} not found.");
		}

		$rightsManager = Driver::getInstance()->getRightsManager();
		$taskId = $rightsManager->getTaskIdByName($taskName);
		if (!$taskId || (int)$taskId <= 0)
		{
			throw new McpException("Disk access task '{$taskName}' not found.");
		}

		$domain = $rightsManager->getSharingDomain($sharing['id']);
		$accessCode = $sharingModel->getToEntity();
		if ($sharing['type'] === SharingTable::TYPE_TO_GROUP)
		{
			$accessCode .= '_K';
		}

		// one transaction, so that the journal of sync compares the targets once and sees the role change
		return AuditTransaction::run(static function (AuditTransaction $transaction) use (
			$rightsManager,
			$folder,
			$sharing,
			$sharingModel,
			$domain,
			$accessCode,
			$taskId,
			$taskName,
		): bool {
			if (!$rightsManager->deleteByDomain($folder->getRealObject(), $domain, $transaction))
			{
				throw new McpException("Failed to delete rights for sharing {$sharing['id']}.");
			}

			if (!$rightsManager->append($folder->getRealObject(), [[
				'ACCESS_CODE' => $accessCode,
				'TASK_ID' => (int)$taskId,
				'DOMAIN' => $domain,
			]], $transaction))
			{
				throw new McpException("Failed to add rights for sharing {$sharing['id']}.");
			}

			if (!$sharingModel->changeTaskName($taskName))
			{
				throw new McpException("Failed to update sharing {$sharing['id']}.");
			}

			return true;
		});
	}

	public function delete(int $sharingId, int $userId): bool
	{
		$sharing = Sharing::getModelList([
			'filter' => ['ID' => $sharingId],
		])[0] ?? null;

		return $sharing !== null && $sharing->delete($userId);
	}

	private function buildSharingErrorMessage(string $sharingEntity, ErrorCollection $errorCollection): string
	{
		$messages = array_map(
			static fn($error): string => $error->getMessage(),
			$errorCollection->toArray(),
		);
		$details = $messages === [] ? 'unknown error' : implode('; ', $messages);

		return "Failed to add sharing for {$sharingEntity}: {$details}.";
	}
}
