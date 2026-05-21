<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;

final class ConfirmFields extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::CONFIRM_FIELDS_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			Call::getId(),
			OpenLine::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CONFIRM_FIELDS') ?? 'Confirm fields';
	}

	protected function getEventName(): string
	{
		return 'EntityFieldsFillingResult:OpenAiFormFill';
	}

	protected function createStateChecker(): ?AIOperationStateChecker
	{
		return null;
	}

	protected function getHint(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_CONFIRM_FIELDS_HINT');
	}

	protected function addCustomParams(JsEvent $jsEvent): JsEvent
	{
		$jobResult = $this->getAIService()->getAIJobResult(FillItemFieldsFromCallTranscription::TYPE_ID);

		return $jsEvent
			->addActionParamInt('mergeUuid', $jobResult?->getJobId())
			->addActionParamString(
				'languageTitle',
				$this->getAIService()->getAILanguage(SummarizeCallTranscription::TYPE_ID)
			)
			->addActionParamInt('summarizeJobId', $jobResult?->getParentJobId())
		;
	}
}
