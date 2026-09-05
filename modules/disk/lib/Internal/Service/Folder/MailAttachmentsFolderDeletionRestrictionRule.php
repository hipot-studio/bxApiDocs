<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Folder;

use Bitrix\Disk\Folder;
use Bitrix\Disk\SpecificFolder;

final class MailAttachmentsFolderDeletionRestrictionRule implements DeletionRestrictionRule
{
	public function isRestricted(Folder $folder): bool
	{
		return $folder->getCode() === SpecificFolder::CODE_FOR_MAIL_ATTACHMENTS;
	}

	public function getRestrictedFolderFilter(): array
	{
		return [
			'=CODE' => SpecificFolder::CODE_FOR_MAIL_ATTACHMENTS,
		];
	}
}
