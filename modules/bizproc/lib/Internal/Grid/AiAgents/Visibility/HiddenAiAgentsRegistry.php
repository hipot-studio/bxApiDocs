<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Visibility;

use Bitrix\Main\Application;

final class HiddenAiAgentsRegistry
{
	/**
	 * @return array<string, AiAgentVisibility>
	 */
	private function getMap(): array
	{
		return [
			'bitrix_ai_day_planner'  => AiAgentVisibility::hiddenEverywhere(),
			'bitrix_booking_ai_call' => AiAgentVisibility::hiddenEverywhere(),
		];
	}

	/**
	 * @return list<string>
	 */
	public function getHiddenSystemCodes(?string $region = null): array
	{
		$region ??= (string)Application::getInstance()->getLicense()->getRegion();

		$hidden = [];
		foreach ($this->getMap() as $systemCode => $visibility)
		{
			if ($visibility->isHiddenFor($region))
			{
				$hidden[] = $systemCode;
			}
		}

		return $hidden;
	}
}
