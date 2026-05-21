<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ScenarioStateChecker;
use Bitrix\Main\Localization\Loc;

final class FillFieldsInChat extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::FILL_FIELDS_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			OpenLine::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CHAT_FILL_FIELDS') ?? 'Fill fields';
	}

	protected function getEventName(): string
	{
		return 'Openline:LaunchCopilot';
	}

	protected function createStateChecker(): ?AIOperationStateChecker
	{
		$state = new OperationState($this->activityId, $this->context->getIdentifier());

		return new ScenarioStateChecker(
			$state,
			fn(OperationState $state) => $state->isFillFieldsScenarioPending(),
			fn(OperationState $state) => $state->isFillFieldsScenarioSuccess(),
			fn(OperationState $state) => $state->isFillFieldsScenarioErrorsLimitExceeded(),
		);
	}

	protected function isDisabled(): bool
	{
		if ($this->getStateChecker()?->isErrorsLimitExceeded())
		{
			return true;
		}

		return !OpenLine::isCopilotProcessingAvailable($this->activityId);
	}

	protected function getHint(): ?string
	{
		if (!OpenLine::isCopilotProcessingAvailable($this->activityId))
		{
			return Loc::getMessage('CRM_TIMELINE_AI_CHAT_FILL_FIELDS_HINT');
		}

		return null;
	}
}
