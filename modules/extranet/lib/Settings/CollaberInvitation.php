<?php

declare(strict_types=1);

namespace Bitrix\Extranet\Settings;

use Bitrix\Bitrix24\Integration\Network\RegisterSettingsSynchronizer;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class CollaberInvitation
{
	private const MODULE_ID = 'extranet';
	private const OPTION_NAME = 'allow_invite_collabers';

	public function enable(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, 'Y');

		if (Loader::includeModule('bitrix24'))
		{
			RegisterSettingsSynchronizer::setRegisterSettings();
		}
	}

	public function disable(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, 'N');

		if (Loader::includeModule('bitrix24'))
		{
			RegisterSettingsSynchronizer::setRegisterSettings();
		}
	}

	public function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, 'Y') === 'Y';
	}
}
