<?php

namespace Bitrix\Crm\Tour\ImOpenLines\AiAgent;

use Bitrix\Crm\Badge\Type\OpenLineStatus;
use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Localization\Loc;

final class OpenLineActivityTour extends Base
{
	protected const OPTION_NAME = 'openlines-ai-agent-activity-tour';

	protected function canShow(): bool
	{
		return !$this->isUserSeenTour();
	}

	protected function getSteps(): array
	{
		$tagId = OpenLineStatus::PROCESSED_BY_AI_AGENT;

		return [
			[
				'id' => self::OPTION_NAME,
				'title' => Loc::getMessage('CRM_TOUR_IMOPENLINES_AI_AGENT_IMOPENLINES_ACTIVITY_TOUR_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_IMOPENLINES_AI_AGENT_IMOPENLINES_ACTIVITY_TOUR_TEXT'),
				'target' => ".crm-timeline__card-status[data-tag-id='$tagId']",
				'position' => 'top',
				'useDynamicTarget' => false,
				'ignoreIfTargetNotFound' => true,
				'reserveTargets' => [],
			],
		];
	}
}
