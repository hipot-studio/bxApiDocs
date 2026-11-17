<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Crm\FileUploader\MailUploaderController;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\UploaderController;

/**
 * The CRM compose form uploads its attachments through crm.FileUploader.MailUploaderController, so a
 * pending token of that form is signed with the name and the options of that very controller. A draft
 * of a CRM context therefore has to rebuild it to redeem its own pending uploads.
 */
final class DraftUploader
{
	/**
	 * Options are rebuilt out of the CRM context of the draft and never taken from the request: the
	 * client cannot name the entity a token was signed for, so a token of another context stays
	 * unusable. Availability is asked as well, exactly as ControllerResolver does on upload.
	 */
	public static function createController(int $entityTypeId, int $entityId): ?UploaderController
	{
		if (
			$entityTypeId <= 0
			|| $entityId <= 0
			|| !Loader::includeModule('ui')
			|| !Loader::includeModule('crm')
		)
		{
			return null;
		}

		$entityTypeName = \CCrmOwnerType::ResolveName($entityTypeId);
		if ($entityTypeName === '')
		{
			return null;
		}

		try
		{
			$controller = new MailUploaderController([
				'ownerId' => $entityId,
				'ownerType' => $entityTypeName,
			]);
		}
		catch (\Throwable)
		{
			return null;
		}

		return $controller->isAvailable() ? $controller : null;
	}
}

