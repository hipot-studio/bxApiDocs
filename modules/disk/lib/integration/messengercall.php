<?php

namespace Bitrix\Disk\Integration;

use Bitrix\Disk\Document\Vibeoffice\Feature;
use Bitrix\Disk\Internal\Service\Document\OfficeEditorAvailability;
use Bitrix\Main\ModuleManager;

final class MessengerCall
{
	public static function isAvailableDocuments(): bool
	{
		return ModuleManager::isModuleInstalled('bitrix24') || OfficeEditorAvailability::isAvailable();
	}

	public static function isEnabledResumes(): bool
	{
		return OfficeEditorAvailability::isAvailable() && Bitrix24Manager::isFeatureEnabled('disk_im_call_resume');
	}

	public static function isEnabledDocuments(): bool
	{
		return OfficeEditorAvailability::isAvailable()
			&& (
				Feature::servesPortalEditing()
				|| Bitrix24Manager::isFeatureEnabled('disk_onlyoffice_edit')
			)
		;
	}

	public static function getInfoHelperCodeForDocuments(): string
	{
		return 'limit_video_calls_documents';
	}

	public static function getInfoHelperCodeForResume(): string
	{
		return 'limit_office_call_meeting_resume';
	}
}