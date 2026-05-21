<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\SuitableAudiosChecker;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AIActivityService;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Button as LayoutButton;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button as FooterButton;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItem;
use Bitrix\Crm\Settings\Crm;
use CCrmActivityDirection;

abstract class AIAction
{
	private ?AIOperationStateChecker $stateChecker = null;
	private bool $isStateCheckerInit = false;
	private ?JsEvent $jsEvent = null;
	private ?AIActivityService $aiService = null;

	abstract protected function getName(): string;
	abstract protected function getEventName(): string;
	abstract protected function createStateChecker(): ?AIOperationStateChecker;

	/**
	 * @return string Scenario identifier from Scenario class
	 */
	abstract public static function getScenario(): string;

	/**
	 * @return string[] List of supported provider IDs
	 */
	abstract public static function getSupportedProviders(): array;

	public function __construct(
		readonly protected int $activityId,
		readonly protected Context $context,
		protected ?AssociatedEntityModel $model,
	) {}

	final public function getCurrentState(): string
	{
		if ($this->isHidden())
		{
			return LayoutButton::STATE_HIDDEN;
		}

		if ($this->getStateChecker()?->isPending())
		{
			return LayoutButton::STATE_AI_LOADING;
		}

		if ($this->isDisabled())
		{
			return LayoutButton::STATE_DISABLED;
		}

		return LayoutButton::STATE_DEFAULT;
	}

	final public function toButton(): FooterButton
	{
		$button = new FooterButton($this->getName(), FooterButton::TYPE_AI, FooterButton::TYPE_AI);

		$state = $this->getCurrentState();
		$hint = $this->getHint();
		$props = $this->getProps();

		$button
			->setAction($state === LayoutButton::STATE_DEFAULT ? $this->getJsEvent() : null)
			->setState($state)
			->setTooltip($hint)
			->setScopeWeb()
		;

		if (!empty($props))
		{
			$button->setProps($props);
		}

		$this->fillAILicenceAttributesForButton($button);

		return $button;
	}

	final public function toMenuItem(): ?MenuItem
	{
		$state = $this->getCurrentState();

		if ($state === LayoutButton::STATE_HIDDEN || $this->isDisabled())
		{
			return null;
		}

		return (new MenuItem($this->getName()))
			->setAction($state === LayoutButton::STATE_DEFAULT ? $this->getJsEvent() : null)
			->setScopeWeb()
		;
	}

	public function isMenuOnly(): bool
	{
		return false;
	}

	public function isHidden(): bool
	{
		return false;
	}

	final protected function getStateChecker(): ?AIOperationStateChecker
	{
		if (!$this->isStateCheckerInit)
		{
			$this->stateChecker = $this->createStateChecker();
			$this->isStateCheckerInit = true;
		}

		return $this->stateChecker;
	}

	final protected function getAIService(): AIActivityService
	{
		if ($this->aiService === null)
		{
			$this->aiService = new AIActivityService($this->activityId, $this->context);
		}

		return $this->aiService;
	}

	final protected function isAudiosValid(): bool
	{
		static $isAudiosValidList = [];

		$originId = (string)$this->model?->get('ORIGIN_ID');

		if (!isset($isAudiosValidList[$originId]))
		{
			$audiosCheckResult = (new SuitableAudiosChecker(
				$originId,
				(int)$this->model?->get('STORAGE_TYPE_ID'),
				(string)$this->model?->get('STORAGE_ELEMENT_IDS')
			))->run();

			$isAudiosValidList[$originId] = $audiosCheckResult->isSuccess();
		}

		return $isAudiosValidList[$originId];
	}

	protected function getHint(): ?string
	{
		return null;
	}

	protected function isDisabled(): bool
	{
		return false;
	}

	protected function addCustomParams(JsEvent $jsEvent): JsEvent
	{
		return $jsEvent;
	}

	protected function getProps(): array
	{
		return [];
	}

	private function getJsEvent(): JsEvent
	{
		if ($this->jsEvent === null)
		{
			$baseEvent = (new JsEvent($this->getEventName()))
				->addActionParamInt('activityId', $this->activityId)
				->addActionParamInt('ownerTypeId', $this->context->getEntityTypeId())
				->addActionParamInt('ownerId', $this->context->getEntityId())
			;

			if ($this->model)
			{
				$baseEvent = $this->addAnalyticsParams($baseEvent, $this->model);
			}

			$this->jsEvent = $this->addCustomParams($baseEvent);
		}

		return $this->jsEvent;
	}

	private function fillAILicenceAttributesForButton(FooterButton $button): void
	{
		$props = [
			'data-activity-id' => $this->activityId,
		];

		if (!AIManager::isAILicenceAccepted($this->context->getUserId()))
		{
			if (Crm::isBox())
			{
				$this->getJsEvent()->addActionParamBoolean('isCopilotAgreementNeedShow', true);
			}
			elseif (!$this->context->getUserPermissions()->isAdmin())
			{
				$props['data-bitrix24-license-feature'] = AIManager::AI_LICENCE_FEATURE_NAME;
			}
		}

		$button->setProps(array_merge($button->getProps() ?? [], $props));
	}

	private function addAnalyticsParams(JsEvent $action, AssociatedEntityModel $model): JsEvent
	{
		$direction = $model->get('DIRECTION');
		if ($direction !== null)
		{
			$action->addActionParamString(
				'activityDirection',
				mb_strtolower(CCrmActivityDirection::ResolveName($direction))
			);
		}

		$providerId = $model->get('PROVIDER_ID');
		if ($providerId !== null)
		{
			$action->addActionParamString('activityProvider', (string)$providerId);
		}

		return $action;
	}
}
