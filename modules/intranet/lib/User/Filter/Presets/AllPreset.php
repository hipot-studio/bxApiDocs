<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Filter\Presets;

use Bitrix\Main\Localization\Loc;

class AllPreset extends FilterPreset
{

	public function getId(): string
	{
		return 'all';
	}

	public function getName(): string
	{
		return Loc::getMessage('INTRANET_USER_LIST_FILTER_PRESET_ALL') ?? '';
	}

	public function getFilterFields(): array
	{
		return [];
	}

	public function isDefault(): bool
	{
		return false;
	}
}
