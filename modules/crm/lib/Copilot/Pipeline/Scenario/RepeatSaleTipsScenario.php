<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Scenario;

final class RepeatSaleTipsScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::REPEAT_SALE_TIPS_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			FillRepeatSaleTips::class,
		];
	}

	public function isEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::RepeatSale);
	}

	public function getDisabledSliderCode(): ?string
	{
		return Scenario::REPEAT_SALE_TIPS_SCENARIO_SLIDER_CODE;
	}
}
