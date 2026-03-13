<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\Presets;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;

final class StartedByMe extends FilterPreset
{
	public function getId(): string
	{
		return 'filter_started_by_me';
	}

	public function getName(): string
	{
		return Loc::getMessage('AI_AGENT_FILTER_PRESET_STARTED_BY_ME') ?? '';
	}

	public function getFilterFields(): array
	{
		return [
			'LAUNCHED_BY' => [(int)CurrentUser::get()->getId()],
		];
	}

	public function isDefault(): bool
	{
		return true;
	}
}
