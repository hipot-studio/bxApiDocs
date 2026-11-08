<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario;

use Bitrix\Crm\Integration\AI\AiMessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation\PresentationResolver;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ActivityBadgeCleaner;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\CallRecordDataProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\MessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SettingsProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SummaryDataProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class TranscriptScenarioBuilder implements ScenarioBuilderInterface
{
	private const TRANSCRIPTION_BLOCK_ID = 'transcription';

	public function __construct(
		private readonly PresentationResolver $presentationResolver,
		private readonly SummaryDataProvider $summaryDataProvider,
		private readonly CallRecordDataProvider $callRecordDataProvider,
		private readonly ActivityBadgeCleaner $activityBadgeCleaner,
		private readonly SettingsProvider $settingsProvider,
	)
	{
	}

	public function getCode(): ScenarioCode
	{
		return ScenarioCode::TRANSCRIPT;
	}

	public function build(ActivityContext $context): Result
	{
		$result = new Result();
		$transcriptResult = $this->summaryDataProvider->loadTranscript($context->request->activityId);
		if (!$transcriptResult->isSuccess())
		{
			return $result->addErrors($transcriptResult->getErrors());
		}

		$transcript = $transcriptResult->getData()['payload'] ?? null;
		if (!is_array($transcript) || !is_string($transcript['transcription'] ?? null) || $transcript['transcription'] === '')
		{
			return $result->addError(new Error('Call transcription not found'));
		}

		$block = [
			'blockId' => self::TRANSCRIPTION_BLOCK_ID,
			'blockType' => self::TRANSCRIPTION_BLOCK_ID,
			'title' => MessageProvider::getTranscriptionBlockTitle(),
			'text' => $transcript['transcription'],
			'createdAt' => is_numeric($transcriptResult->getData()['createdAt'] ?? null)
				? (int)$transcriptResult->getData()['createdAt']
				: null,
			'aiLanguage' => $this->summaryDataProvider->getTranscriptAiLanguage($context->request->activityId),
			'minimized' => false,
		];
		$title = MessageProvider::getTranscriptionBlockTitle();
		$drawerData = [
			'title' => $title,
			'subtitle' => $this->presentationResolver->buildSubtitle($context),
			'settings' => $this->prepareSettings(),
			'record' => $this->callRecordDataProvider->load($context->activity),
			'infoPopup' => $this->presentationResolver->buildInfoPopup($context),
			'anchors' => [[
				'text' => $title,
				'isActive' => true,
				'blockId' => self::TRANSCRIPTION_BLOCK_ID,
			]],
			'assessmentBlocks' => [],
			'assessmentSetting' => null,
			'blocks' => [$block],
			'aiDisclaimer' => AiMessageProvider::getAiDisclaimerData()['html'] ?? null,
		];

		$this->activityBadgeCleaner->removeByOwner($context);

		return $result->setData(['drawerData' => $drawerData]);
	}

	private function prepareSettings(): array
	{
		return array_values(array_filter([
			$this->settingsProvider->getButton(SettingsProvider::SHARE_SLIDER),
		]));
	}
}
