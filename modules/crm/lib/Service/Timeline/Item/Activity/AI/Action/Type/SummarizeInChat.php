<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\OpenLineScenarioAvailability;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ResultStateChecker;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class SummarizeInChat extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::SUMMARIZE_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			OpenLine::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CHAT_SUMMARIZE') ?? 'Summarize';
	}

	protected function getEventName(): string
	{
		return 'Openline:LaunchCopilot';
	}

	protected function createStateChecker(): ?AIOperationStateChecker
	{
		$result = JobRepository::getInstance()->getSummarizeCallTranscriptionResultByActivity($this->activityId);

		return $result !== null ? new ResultStateChecker($result) : null;
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
		return $jsEvent->addActionParamString('scenario', Scenario::SUMMARIZE_SCENARIO);
	}

	protected function getMenuIcon(): Outline
	{
		return Outline::SIGMA_SUMM;
	}
}
