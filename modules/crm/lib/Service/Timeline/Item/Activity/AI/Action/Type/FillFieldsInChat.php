<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\OpenLineScenarioAvailability;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ScenarioStateChecker;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

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

	protected function getHint(): ?string
	{
		if (!$this->isDisabledState())
		{
			return null;
		}

		$stateChecker = $this->getStateChecker();
		if ($stateChecker?->isPending() || $stateChecker?->isErrorsLimitExceeded())
		{
			return null;
		}

		return Loc::getMessage('CRM_TIMELINE_AI_CHAT_FILL_FIELDS_HINT');
	}

	protected function isDisabled(): bool
	{
		$stateChecker = $this->getStateChecker();
		$messages = OpenLine::getMessagesForCopilot($this->activityId);

		return OpenLineScenarioAvailability::isDisabled(
			OpenLine::isCopilotProcessingAvailable($this->activityId, $messages, false),
			OpenLine::isCopilotProcessingAvailable($this->activityId, $messages),
			$stateChecker?->isSuccess() ?? false,
			$stateChecker?->isPending() ?? false,
			$stateChecker?->isErrorsLimitExceeded() ?? false,
		);
	}

	protected function addCustomParams(JsEvent $jsEvent): JsEvent
	{
		return $jsEvent->addActionParamString('scenario', Scenario::FILL_FIELDS_SCENARIO);
	}

	protected function getMenuIcon(): Outline
	{
		return Outline::CRM_FIELD_SIMPLE;
	}
}
