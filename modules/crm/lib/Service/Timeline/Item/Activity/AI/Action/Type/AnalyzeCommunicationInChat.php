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

final class AnalyzeCommunicationInChat extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::ANALYZE_COMMUNICATION_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			OpenLine::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CHAT_ANALYZE_COMMUNICATION') ?? 'Analyze communication';
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
			fn(OperationState $state) => $state->isAnalyzeCommunicationScenarioPending(),
			fn(OperationState $state) => $state->isAnalyzeCommunicationScenarioSuccess(),
			fn(OperationState $state) => $state->isAnalyzeCommunicationScenarioErrorsLimitExceeded(),
		);
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

	public function isMenuOnly(): bool
	{
		return true;
	}

	protected function addCustomParams(JsEvent $jsEvent): JsEvent
	{
		return $jsEvent->addActionParamString('scenario', Scenario::ANALYZE_COMMUNICATION_SCENARIO);
	}

	protected function getMenuIcon(): Outline
	{
		return Outline::ADD_TIMELINE;
	}
}
