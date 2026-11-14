<?php

namespace Bitrix\Crm\Tour;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryVisibility;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;

class AhaMomentClientSummary extends Base
{
	protected const OPTION_NAME = 'aha-moment-client-summary';

	private const SUMMARY_BUTTON_SELECTOR = '.crm-ai-summary-toolbar-button';

	protected function canShow(): bool
	{
		if ($this->isUserSeenTour())
		{
			return false;
		}

		$userId = Container::getInstance()->getContext()->getUserId();

		// the hint points at the button of the header, so it is offered by exactly what draws that button
		return ServiceLocator::getInstance()->get(ClientSummaryVisibility::class)->isLaunchable($userId);
	}

	protected function getSteps(): array
	{
		$copilotName = AIManager::getCopilotName();

		return [
			[
				'id' => self::OPTION_NAME,
				'title' => Loc::getMessage(
					'CRM_TOUR_AHA_MOMENT_CLIENT_SUMMARY_TITLE',
					['#COPILOT_NAME#' => $copilotName]
				),
				'text' => Loc::getMessage(
					'CRM_TOUR_AHA_MOMENT_CLIENT_SUMMARY_TEXT',
					['#COPILOT_NAME#' => $copilotName]
				),
				'target' => self::SUMMARY_BUTTON_SELECTOR,
				'ignoreIfTargetNotFound' => true,
			],
		];
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => 400,
				],
			],
		];
	}
}
