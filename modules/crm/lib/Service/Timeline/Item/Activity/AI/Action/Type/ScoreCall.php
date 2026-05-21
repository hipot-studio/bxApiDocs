<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ScenarioStateChecker;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;

final class ScoreCall extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::CALL_SCORING_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			Call::getId(),
		];
	}

	public function isHidden(): bool
	{
		return $this->getStateChecker()?->isSuccess();
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CALL_SCORE') ?? 'Score call';
	}

	protected function getEventName(): string
	{
		return 'Call:LaunchCopilot';
	}

	protected function createStateChecker(): ?AIOperationStateChecker
	{
		$state = new OperationState($this->activityId, $this->context->getIdentifier());

		return new ScenarioStateChecker(
			$state,
			fn(OperationState $state) => $state->isCallScoringScenarioPending(),
			fn(OperationState $state) => $state->isCallScoringScenarioSuccess(),
			fn(OperationState $state) => $state->isCallScoringScenarioErrorsLimitExceeded(),
		);
	}

	protected function isDisabled(): bool
	{
		if (!$this->isAudiosValid())
		{
			return true;
		}

		return $this->getStateChecker()?->isPending() || $this->getStateChecker()?->isErrorsLimitExceeded();
	}

	protected function getHint(): ?string
	{
		if (!$this->isAudiosValid())
		{
			return Loc::getMessage(
				'CRM_TIMELINE_AI_CALL_SCORE_ERROR_HINT',
				['#COPILOT_NAME#' => AIManager::getCopilotName()]
			);
		}

		if ($this->getStateChecker()?->isSuccess())
		{
			return Loc::getMessage(
				'CRM_TIMELINE_AI_CALL_SCORE_HINT',
				['#COPILOT_NAME#' => AIManager::getCopilotName()]
			);
		}

		return null;
	}

	protected function addCustomParams(JsEvent $jsEvent): JsEvent
	{
		return $jsEvent->addActionParamString('scenario', Scenario::CALL_SCORING_SCENARIO);
	}
}
