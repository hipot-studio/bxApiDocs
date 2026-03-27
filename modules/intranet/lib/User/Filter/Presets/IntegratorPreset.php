<?php

namespace Bitrix\Intranet\User\Filter\Presets;

use Bitrix\Main\Localization\Loc;

class IntegratorPreset extends FilterPreset
{
	public function getId(): string
	{
		return 'integrator';
	}

	public function getName(): string
	{
		return Loc::getMessage('INTRANET_USER_LIST_FILTER_PRESET_INTEGRATOR') ?? '';
	}

	public function getFilterFields(): array
	{
		return [
			'INTEGRATOR' => 'Y',
		];
	}

	public function isDefault(): bool
	{
		return false;
	}
}