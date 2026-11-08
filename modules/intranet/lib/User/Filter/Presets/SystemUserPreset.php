<?php

namespace Bitrix\Intranet\User\Filter\Presets;

use Bitrix\Intranet\User\Filter\IntranetUserSettings;
use Bitrix\Main\Localization\Loc;

class SystemUserPreset extends FilterPreset
{
	public function getId(): string
	{
		return 'system_user';
	}

	public function getName(): string
	{
		return Loc::getMessage('INTRANET_USER_LIST_FILTER_PRESET_SYSTEM_USER') ?? '';
	}

	public function getFilterFields(): array
	{
		return [
			IntranetUserSettings::SYSTEM_USER_FIELD => 'Y',
		];
	}

	public function isDefault(): bool
	{
		return false;
	}
}
