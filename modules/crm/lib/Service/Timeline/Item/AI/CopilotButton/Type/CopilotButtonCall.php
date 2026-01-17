<?php

namespace Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\Type;

use Bitrix\AI\Config;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\SuitableAudiosChecker;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\BaseButton;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItem;
use Bitrix\Main\Localization\Loc;

final class CopilotButtonCall extends BaseButton
{
	private readonly OperationState $operationState;

	public function __construct(
		protected int $activityId,
		protected Context $context,
		protected ?AssociatedEntityModel $model,
	)
	{
		$this->operationState = new OperationState(
			$activityId,
			$context->getEntityTypeId(),
			$context->getEntityId(),
		);

		parent::__construct($activityId, $context, $model);
	}

	protected function createJsEventAction(): JsEvent
	{
		return $this->createBaseJsEvent('Call:LaunchCopilot');
	}

	protected function addCustomJsEventParams(JsEvent $jsEvent): JsEvent
	{
		return $jsEvent
			->addActionParamString('scenario', Scenario::FULL_SCENARIO)
			->addActionParamBoolean('isCopilotBannerNeedShow', $this->isCopilotBannerNeedShow())
		;
	}

	protected function determineButtonState(): string
	{
		if ($this->operationState->isLaunchOperationsPending())
		{
			return Layout\Button::STATE_AI_LOADING;
		}

		if ($this->isDisabled())
		{
			return Layout\Button::STATE_DISABLED;
		}

		return Layout\Button::STATE_DEFAULT;
	}

	protected function isDisabled(): bool
	{
		if (!$this->isAudiosValid())
		{
			return true;
		}

		$isFillFieldsScenarioSuccess = $this->operationState->isFillFieldsScenarioSuccess();
		$isCallScoringScenarioSuccess = $this->operationState->isCallScoringScenarioSuccess();
		if ($isFillFieldsScenarioSuccess && $isCallScoringScenarioSuccess)
		{
			return true;
		}

		if (!Scenario::isEnabledScenario(Scenario::FULL_SCENARIO))
		{
			return true;
		}

		if (
			$isCallScoringScenarioSuccess
			&& !Scenario::isEnabledScenario(Scenario::FILL_FIELDS_SCENARIO)
		)
		{
			return true;
		}

		if (
			$isFillFieldsScenarioSuccess
			&& !Scenario::isEnabledScenario(Scenario::CALL_SCORING_SCENARIO)
		)
		{
			return true;
		}

		$isFillFieldsScenarioError = $this->operationState->isFillFieldsScenarioErrorsLimitExceeded();
		$isCallScoringScenarioError = $this->operationState->isCallScoringScenarioErrorsLimitExceeded();
		if ($isFillFieldsScenarioError && $isCallScoringScenarioError)
		{
			return true;
		}

		return ($isFillFieldsScenarioSuccess && $isCallScoringScenarioError)
			|| ($isCallScoringScenarioSuccess && $isFillFieldsScenarioError)
		;
	}

	protected function buildTooltip(): ?string
	{
		if (!$this->isAudiosValid())
		{
			return Loc::getMessage('CRM_TIMELINE_ITEM_COPILOT_ERROR_TOOLTIP');
		}

		if (
			$this->operationState->isFillFieldsScenarioSuccess()
			|| $this->operationState->isCallScoringScenarioSuccess()
		)
		{
			return Loc::getMessage('CRM_TIMELINE_BUTTON_TIP_COPILOT');
		}

		return null;
	}

	protected function buildMenu(): array
	{
		$fillFieldsScenarioState = Layout\Button::STATE_DEFAULT;
		$callScoringScenarioState = Layout\Button::STATE_DEFAULT;
		$fillFieldsScenarioAction = (clone $this->jsEventAction)
			->addActionParamString('scenario', Scenario::FILL_FIELDS_SCENARIO)
		;
		$callScoringScenarioAction = (clone $this->jsEventAction)
			->addActionParamString('scenario', Scenario::CALL_SCORING_SCENARIO)
		;

		// logic for determining scenario states
		if ($this->operationState->isFillFieldsScenarioPending())
		{
			$fillFieldsScenarioState = Layout\Button::STATE_AI_LOADING;
			$fillFieldsScenarioAction = null;
		}
		elseif (
			$this->operationState->isFillFieldsScenarioSuccess()
			|| $this->operationState->isFillFieldsScenarioErrorsLimitExceeded()
		)
		{
			$fillFieldsScenarioState = Layout\Button::STATE_AI_SUCCESS;
			$fillFieldsScenarioAction = null;
		}

		if ($this->operationState->isCallScoringScenarioPending())
		{
			$callScoringScenarioState = Layout\Button::STATE_AI_LOADING;
			$callScoringScenarioAction = null;
		}
		elseif (
			$this->operationState->isCallScoringScenarioSuccess()
			|| $this->operationState->isCallScoringScenarioErrorsLimitExceeded()
		)
		{
			$callScoringScenarioState = Layout\Button::STATE_AI_SUCCESS;
			$callScoringScenarioAction = null;
		}

		if (!$this->isAudiosValid())
		{
			$fillFieldsScenarioState = Layout\Button::STATE_DISABLED;
			$fillFieldsScenarioAction = null;

			$callScoringScenarioState = Layout\Button::STATE_DISABLED;
			$callScoringScenarioAction = null;
		}

		if (
			$fillFieldsScenarioState === Layout\Button::STATE_DEFAULT
			&& !Scenario::isEnabledScenario(Scenario::FILL_FIELDS_SCENARIO)
		)
		{
			$fillFieldsScenarioState = Layout\Button::STATE_LOCKED;
		}

		if (
			$callScoringScenarioState === Layout\Button::STATE_DEFAULT
			&& !Scenario::isEnabledScenario(Scenario::CALL_SCORING_SCENARIO)
		)
		{
			$callScoringScenarioState = Layout\Button::STATE_LOCKED;
		}

		return [
			'fillFields' => (new MenuItem(Loc::getMessage('CRM_TIMELINE_BUTTON_COPILOT_MENU_FILL_FIELDS')))
				->setAction($fillFieldsScenarioAction)
				->setState($fillFieldsScenarioState)
				->setScopeWeb(),
			'callScoring' => (new MenuItem(Loc::getMessage('CRM_TIMELINE_BUTTON_COPILOT_MENU_CALL_SCORING')))
				->setAction($callScoringScenarioAction)
				->setState($callScoringScenarioState)
				->setScopeWeb(),
		];
	}

	private function isAudiosValid(): bool
	{
		static $isAudiosValidList = [];

		$originId = (string)$this->model?->get('ORIGIN_ID');

		if (!isset($isAudiosValidList[$originId])) {
			$audiosCheckResult = (new SuitableAudiosChecker(
				$originId,
				(int)$this->model?->get('STORAGE_TYPE_ID'),
				(string)$this->model?->get('STORAGE_ELEMENT_IDS')
			))->run();

			$isAudiosValidList[$originId] = $audiosCheckResult->isSuccess();
		}

		return $isAudiosValidList[$originId];
	}

	private function isCopilotBannerNeedShow(): bool
	{
		return Config::getPersonalValue('first_launch') !== 'N';
	}
}
