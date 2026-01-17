<?php

namespace Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\Type;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\BaseButton;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;

final class CopilotButtonOpenLine extends BaseButton
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
		return $this->createBaseJsEvent('Openline:LaunchCopilot');
	}

	protected function determineButtonState(): string
	{
		if ($this->operationState->isFillFieldsScenarioPending())
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
		if ($this->operationState->isFillFieldsScenarioErrorsLimitExceeded())
		{
			return true;
		}

		return !OpenLine::isCopilotProcessingAvailable($this->activityId);
	}

	protected function buildTooltip(): ?string
	{
		if (!OpenLine::isCopilotProcessingAvailable($this->activityId))
		{
			return Loc::getMessage('CRM_TIMELINE_ACTIVITY_OPENLINE_COPILOT_TOOLTIP');
		}

		return null;
	}
}
