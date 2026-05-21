<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\RepeatSale;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ResultStateChecker;
use Bitrix\Main\Localization\Loc;

final class FillRepeatSaleTips extends AIAction
{
	public const BUTTON_TARGET_ID = 'crm-timeline-activity-repeat-sale-copilot-button';

	public static function getScenario(): string
	{
		return Scenario::REPEAT_SALE_TIPS_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			RepeatSale::getId(),
		];
	}

	protected function getName(): string
	{
		return AIManager::getCopilotName();
	}

	protected function getEventName(): string
	{
		return 'Activity:RepeatSale:LaunchCopilot';
	}

	protected function createStateChecker(): ?AIOperationStateChecker
	{
		$result = JobRepository::getInstance()->getFillRepeatSaleTipsByActivity($this->activityId);

		return $result !== null ? new ResultStateChecker($result) : null;
	}

	protected function isDisabled(): bool
	{
		return $this->getStateChecker()?->isSuccess()
			|| $this->getStateChecker()?->isErrorsLimitExceeded()
		;
	}

	protected function getHint(): ?string
	{
		if ($this->getStateChecker()?->isPending())
		{
			return null;
		}

		if ($this->getStateChecker()?->isSuccess())
		{
			return Loc::getMessage(
				'CRM_TIMELINE_AI_REPEAT_SALE_HINT_SUCCESS',
				['#COPILOT_NAME#' => AIManager::getCopilotName()]
			);
		}

		if ($this->getStateChecker()?->isErrorsLimitExceeded())
		{
			return Loc::getMessage(
				'CRM_TIMELINE_AI_REPEAT_SALE_HINT_ERROR',
				['#COPILOT_NAME#' => AIManager::getCopilotName()]
			);
		}

		return Loc::getMessage(
			'CRM_TIMELINE_AI_REPEAT_SALE_HINT',
			['#COPILOT_NAME#' => AIManager::getCopilotName()]
		);
	}

	protected function getProps(): array
	{
		return [
			'id' => self::BUTTON_TARGET_ID,
		];
	}
}
