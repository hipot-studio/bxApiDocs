<?php

namespace Bitrix\Mail\Access;

use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Main\Localization\Loc;

class SectionDictionary
{
	private const SECTION_SETTINGS = 100;
	private const SECTION_MAIL = 200;

	/**
	 * @return array<int, array<string>>
	 */
	public static function getMap(): array
	{
		$mailPermissions = [
			PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_VIEW,
			PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_EDIT,
			PermissionDictionary::MAIL_MAILBOX_CONNECT,
		];

		if (Feature::isCrmAvailable())
		{
			$mailPermissions[] = PermissionDictionary::MAIL_MAILBOX_CRM_INTEGRATION_EDIT;
		}

		return [
			self::SECTION_MAIL => $mailPermissions,
			self::SECTION_SETTINGS => [
				PermissionDictionary::MAIL_ACCESS_RIGHTS_EDIT,
			],
		];
	}

	public static function getTitle(int $sectionId): string
	{
		$map = [
			self::SECTION_MAIL => Loc::getMessage('MAIL_CONFIG_PERMISSIONS_SECTION_MAIL_TITLE'),
			self::SECTION_SETTINGS => Loc::getMessage('MAIL_CONFIG_PERMISSIONS_SECTION_SETTINGS_TITLE'),
		];

		return $map[$sectionId] ?? '';
	}
}
