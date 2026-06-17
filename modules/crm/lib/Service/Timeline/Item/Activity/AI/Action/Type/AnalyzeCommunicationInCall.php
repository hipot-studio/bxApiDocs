<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ResultStateChecker;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class AnalyzeCommunicationInCall extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::ANALYZE_COMMUNICATION_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			Call::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CALL_ANALYZE_COMMUNICATION') ?? 'Analyze communication';
	}

	protected function getEventName(): string
	{
		return 'Call:LaunchCopilot';
	}

	protected function createStateChecker(): ?AIOperationStateChecker
	{
		$result = JobRepository::getInstance()->getAnalyzeCommunicationResult($this->activityId);

		return $result !== null ? new ResultStateChecker($result) : null;
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
		return $this->isDisabled();
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
