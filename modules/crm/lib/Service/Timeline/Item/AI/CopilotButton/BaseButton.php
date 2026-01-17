<?php

namespace Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;
use Bitrix\Crm\Service\Timeline\Item\Mixin\CopilotHelper;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Main\Localization\Loc;

Container::getInstance()->getLocalization()->loadMessages();

abstract class BaseButton extends Button
{
	use CopilotHelper;

	protected ?JsEvent $jsEventAction;

	public function __construct(
		protected int $activityId,
		protected Context $context,
		protected ?AssociatedEntityModel $model,
	)
	{
		parent::__construct(
			Loc::getMessage('CRM_COMMON_COPILOT'),
			Button::TYPE_AI,
			Button::TYPE_AI
		);

		$this->jsEventAction = $this->createJsEventAction();

		$this->initialize();
	}

	abstract protected function createJsEventAction(): JsEvent;
	abstract protected function determineButtonState(): string;
	abstract protected function isDisabled(): bool;
	abstract protected function buildTooltip(): ?string;

	protected function buildMenu(): array
	{
		return [];
	}

	protected function buildProps(): array
	{
		return [];
	}

	protected function addCustomJsEventParams(JsEvent $jsEvent): JsEvent
	{
		return $jsEvent;
	}

	protected function createBaseJsEvent(string $eventName): JsEvent
	{
		$jsEvent = (new JsEvent($eventName))
			->addActionParamInt('activityId', $this->activityId)
			->addActionParamInt('ownerTypeId', $this->context->getEntityTypeId())
			->addActionParamInt('ownerId', $this->context->getEntityId())
		;

		return $this->addCustomJsEventParams($jsEvent);
	}

	private function initialize(): void
	{
		$buttonState = $this->determineButtonState();
		$buttonTooltip = $this->buildTooltip();
		$menuItems = $this->buildMenu();
		$props = $this->buildProps();

		$this
			->fillAILicenceAttributes()
			->setAction($buttonState === Layout\Button::STATE_DEFAULT ? $this->jsEventAction : null)
			->setState($buttonState)
			->setTooltip($buttonTooltip)
			->setScopeWeb()
		;

		if (!empty($menuItems))
		{
			$this->setMenuItems($menuItems);
		}

		if (!empty($props))
		{
			$this->setProps($props);
		}
	}
}
