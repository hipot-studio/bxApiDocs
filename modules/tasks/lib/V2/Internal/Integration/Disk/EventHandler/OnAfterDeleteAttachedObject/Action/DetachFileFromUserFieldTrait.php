<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\EventHandler\OnAfterDeleteAttachedObject\Action;

use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\FileBbCodeService;
use CUserTypeManager;

trait DetachFileFromUserFieldTrait
{
	private const SYSTEM_USER_ID = 0;

	private function detachFileFromUserField(string $entityCode, int $entityId, int $attachId): void
	{
		$ufManager = $this->getUfManager();

		$currentAttachmentIds = $ufManager->GetUserFields($entityCode, $entityId)[UserField::TASK_ATTACHMENTS]['VALUE'] ?? [];
		$currentAttachmentIds = is_array($currentAttachmentIds) ? array_values($currentAttachmentIds) : [];

		if (empty($currentAttachmentIds))
		{
			return;
		}

		$updatedAttachIds = array_values(array_diff($currentAttachmentIds, [$attachId]));
		if ($updatedAttachIds === $currentAttachmentIds)
		{
			return;
		}

		$fields = [
			UserField::TASK_ATTACHMENTS => $updatedAttachIds,
		];

		$ufManager->Update($entityCode, $entityId, $fields, self::SYSTEM_USER_ID);
	}

	private function stripDetachedFileFromDescription(
		FileBbCodeService $fileBbCodeService,
		string $description,
		int $attachId,
		int $objectId,
	): ?string
	{
		if (!$fileBbCodeService->containsBbCode($description))
		{
			return null;
		}

		$cleanedDescription = $fileBbCodeService->stripDetachedBbCodes(
			$description,
			[$attachId, FileUserType::NEW_FILE_PREFIX . $objectId],
		);

		if ($cleanedDescription === $description)
		{
			return null;
		}

		return preg_replace('/ {2,}/', ' ', $cleanedDescription);
	}

	private function getUfManager(): CUserTypeManager
	{
		global $USER_FIELD_MANAGER;

		return $USER_FIELD_MANAGER;
	}
}
