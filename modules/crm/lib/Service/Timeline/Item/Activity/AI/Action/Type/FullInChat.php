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

final class FullInChat extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::FULL_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			OpenLine::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CHAT_FULL') ?? 'Full processing';
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
			fn(OperationState $state) => $state->isFullChatScenarioPending(),
			fn(OperationState $state) => $state->isFullChatScenarioSuccess(),
			fn(OperationState $state) => $state->isFullChatScenarioErrorsLimitExceeded(),
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

	public function isHidden(): bool
	{
		return !Scenario::isManualFullScenarioAvailable(OpenLine::getId());
	}

	public function isMenuOnly(): bool
	{
		return true;
	}

	protected function addCustomParams(JsEvent $jsEvent): JsEvent
	{
		return $jsEvent->addActionParamString('scenario', Scenario::FULL_SCENARIO);
	}

	protected function getMenuIcon(): Outline
	{
		return Outline::AI_STARS;
	}
}
