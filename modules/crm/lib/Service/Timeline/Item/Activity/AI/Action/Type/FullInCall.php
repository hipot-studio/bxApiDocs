<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ScenarioStateChecker;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class FullInCall extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::FULL_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			Call::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CALL_FULL') ?? 'Full processing';
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
			fn(OperationState $state) => $state->isFullCallScenarioPending(),
			fn(OperationState $state) => $state->isFullCallScenarioSuccess(),
			fn(OperationState $state) => $state->isFullCallScenarioErrorsLimitExceeded(),
		);
	}

	protected function isDisabled(): bool
	{
		if (!$this->isAudiosValid())
		{
			return true;
		}

		return $this->getStateChecker()?->isSuccess()
			|| $this->getStateChecker()?->isErrorsLimitExceeded()
			|| $this->getStateChecker()?->isPending()
		;
	}

	public function isHidden(): bool
	{
		if (!Scenario::isManualFullScenarioAvailable(Call::getId()))
		{
			return true;
		}

		return $this->isDisabled();
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
