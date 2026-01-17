<?php

namespace Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\Type;

use Bitrix\Crm\Integration\AI\Dto\RepeatSale\FillRepeatSaleTipsPayload;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\BaseButton;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;

final class CopilotButtonRepeatSale extends BaseButton
{
	public const BUTTON_TARGET_ID = 'crm-timeline-activity-repeat-sale-copilot-button';

	/** @var Result<FillRepeatSaleTipsPayload>|null */
	private readonly ?Result $operationState;

	public function __construct(
		protected int $activityId,
		protected Context $context,
		protected ?AssociatedEntityModel $model,
	)
	{
		$this->operationState = JobRepository::getInstance()
			->getFillRepeatSaleTipsByActivity($activityId)
		;

		parent::__construct($activityId, $context, $model);
	}

	protected function createJsEventAction(): JsEvent
	{
		return $this->createBaseJsEvent('Activity:RepeatSale:LaunchCopilot');
	}

	protected function determineButtonState(): string
	{
		if ($this->operationState?->isPending())
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
		if ($this->operationState?->isSuccess())
		{
			return true;
		}

		if ($this->operationState?->isErrorsLimitExceeded())
		{
			return true;
		}

		return false;
	}

	protected function buildTooltip(): ?string
	{
		if ($this->operationState?->isPending())
		{
			return null;
		}

		if ($this->operationState?->isSuccess())
		{
			return Loc::getMessage('CRM_TIMELINE_ACTIVITY_REPEAT_SALE_COPILOT_TOOLTIP_SUCCESS');
		}

		return Loc::getMessage('CRM_TIMELINE_ACTIVITY_REPEAT_SALE_COPILOT_TOOLTIP');
	}

	protected function buildProps(): array
	{
		return [
			'id' => self::BUTTON_TARGET_ID,
		];
	}
}
