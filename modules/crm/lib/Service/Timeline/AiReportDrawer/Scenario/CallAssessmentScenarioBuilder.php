<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario;

use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;
use Bitrix\Crm\Copilot\CallAssessment\HintStateResolver;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\AiMessageProvider;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation\PresentationResolver;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\CallRecordDataProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\MessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SettingsProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SummaryDataProvider;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\Json;

final class CallAssessmentScenarioBuilder implements ScenarioBuilderInterface
{
	private const ASSESSMENT_BLOCK_ID = 'assessment';
	private const LEGACY_ASSESSMENT_BLOCK_ID = 'legacyAssessment';
	private const SUMMARY_BLOCK_ID = 'summary';
	private const TRANSCRIPTION_BLOCK_ID = 'transcription';

	/** @var array<int, array<string, array{description: string}>> */
	private array $assessmentCriteriaByNameCache = [];
	private readonly CallRecordDataProvider $callRecordDataProvider;

	public function __construct(
		private readonly PresentationResolver $presentationResolver,
		private readonly SummaryDataProvider $summaryDataProvider,
		private readonly JobRepository $jobRepository,
		private readonly SettingsProvider $settingsProvider,
		?CallRecordDataProvider $callRecordDataProvider = null,
	)
	{
		$this->callRecordDataProvider = $callRecordDataProvider ?? new CallRecordDataProvider();
	}

	public function getCode(): ScenarioCode
	{
		return ScenarioCode::CALL_ASSESSMENT;
	}

	public function build(ActivityContext $context): Result
	{
		$result = new Result();
		$data = [
			'aiDisclaimer' => AiMessageProvider::getAiDisclaimerData()['html'] ?? null,
			'callRecord' => $this->callRecordDataProvider->load($context->activity),
		];

		$transcriptResult = $this->summaryDataProvider->loadTranscript($context->request->activityId);
		if (!$transcriptResult->isSuccess())
		{
			return $result->addErrors($transcriptResult->getErrors());
		}

		$data['transcript'] = $transcriptResult->getData()['payload'] ?? null;
		if (is_array($data['transcript']))
		{
			$data['transcript']['createdAt'] = $transcriptResult->getData()['createdAt'] ?? null;
			$data['transcript']['aiLanguage'] = $this->summaryDataProvider->getTranscriptAiLanguage(
				$context->request->activityId,
			);
		}

		// Summary is optional: legacy (v1) assessments and portals with summarization disabled have no job.
		$summaryResult = $this->summaryDataProvider->loadSummary($context->request->activityId);
		$data['summary'] = $summaryResult->isSuccess()
			? ($summaryResult->getData()['payload'] ?? null)
			: null
		;
		if (is_array($data['summary']))
		{
			$data['summary']['createdAt'] = $summaryResult->getData()['createdAt'] ?? null;
			$data['summary']['aiLanguage'] = $this->summaryDataProvider->getSummaryAiLanguage(
				$context->request->activityId,
			);
		}

		$assessmentHistory = $this->loadAssessmentHistory($context->request->activityId);
		$assessmentContent = $this->prepareAssessmentContent($context->request->activityId, $assessmentHistory);

		$assessmentBlocks = $assessmentContent['assessmentBlocks'];
		$blocks = array_merge($assessmentContent['blocks'], $this->prepareBlocks($data));

		return $result->setData([
			'drawerData' => [
				'title' => $this->prepareTitle($data),
				'subtitle' => $this->presentationResolver->buildSubtitle($context),
				'settings' => $this->prepareSettings(),
				'record' => $data['callRecord'],
				'infoPopup' => $this->presentationResolver->buildInfoPopup($context),
				'anchors' => $this->prepareAnchors($assessmentBlocks, $blocks),
				'assessmentBlocks' => $assessmentBlocks,
				'assessmentSetting' => $this->prepareAssessmentSetting(
					$assessmentHistory,
					$context->request->assessmentSettingsId,
				),
				'blocks' => $blocks,
				'aiDisclaimer' => $data['aiDisclaimer'],
			],
		]);
	}

	private function prepareSettings(): array
	{
		return array_values(array_filter([
			$this->settingsProvider->getButton(SettingsProvider::CHOOSE_NEW_SCRIPT),
			$this->settingsProvider->getButton(SettingsProvider::SHARE_SLIDER),
			$this->settingsProvider->getButton(SettingsProvider::ANALYTICS),
			$this->settingsProvider->getButton(SettingsProvider::DELIMITER),
			$this->settingsProvider->getButton(SettingsProvider::HOW_IT_WORKS),
		]));
	}

	private function prepareTitle(array $data): string
	{
		$theme = $data['summary']['data']['theme'] ?? null;

		return is_string($theme) ? $theme : '';
	}

	private function prepareAssessmentContent(int $activityId, array $assessmentHistory): array
	{
		if (empty($assessmentHistory))
		{
			return [
				'assessmentBlocks' => [],
				'blocks' => [],
			];
		}

		$assessmentBlocks = [];
		$legacyAssessmentBlocks = [];
		$legacyAssessmentIndex = 0;
		$callScoringResultsByJobId = $this->loadCallScoringResultsByJobIds($activityId, $assessmentHistory);

		foreach ($assessmentHistory as $index => $callQuality)
		{
			if (!is_array($callQuality))
			{
				continue;
			}

			$jobId = (int)($callQuality['JOB_ID'] ?? 0);
			$callQuality = $this->enrichAssessmentHistoryItem(
				$callQuality,
				$callScoringResultsByJobId[$jobId] ?? null,
			);

			if ($this->isLegacyAssessment($callQuality))
			{
				$legacyAssessmentBlock = $this->prepareLegacyAssessmentBlock($callQuality, $legacyAssessmentIndex);
				if ($legacyAssessmentBlock !== null)
				{
					$legacyAssessmentBlocks[] = $legacyAssessmentBlock;
					$legacyAssessmentIndex++;
				}

				continue;
			}

			$assessmentBlocks[] = $this->prepareAssessmentBlock($callQuality, $index);
		}

		return [
			'assessmentBlocks' => $assessmentBlocks,
			'blocks' => $legacyAssessmentBlocks,
		];
	}

	private function prepareAssessmentSetting(array $assessmentHistory, ?int $assessmentSettingsId = null): ?array
	{
		$currentAssessment = $assessmentHistory[0] ?? null;
		if (is_array($currentAssessment))
		{
			$id = (int)($currentAssessment['ASSESSMENT_SETTING_ID'] ?? 0);
			if ($id > 0)
			{
				return [
					'id' => $id,
					'title' => (string)($currentAssessment['TITLE'] ?? ''),
					'promptUpdatedAt' => $this->normalizeDateTimeValue($currentAssessment['PROMPT_UPDATED_AT'] ?? null),
					'shouldShowReassessmentBadge' => $this->isPromptUpdatedAfterAssessment($currentAssessment),
				];
			}
		}

		if (($assessmentSettingsId ?? 0) > 0)
		{
			$controller = CopilotCallAssessmentController::getInstance();
			$filter = ['=ID' => $assessmentSettingsId];
			$additionalFilter = $controller->getCurrentAvailableAssessmentFilter();
			if ($additionalFilter)
			{
				$filter[] = $additionalFilter;
			}

			$callAssessment = $controller->getList([
				'filter' => $filter,
				'limit' => 1,
			])->current();
			if ($callAssessment)
			{
				return [
					'id' => $callAssessment->getId(),
					'title' => (string)$callAssessment->getTitle(),
					'promptUpdatedAt' => $this->normalizeDateTimeValue($callAssessment->getUpdatedAt()),
					'shouldShowReassessmentBadge' => false,
				];
			}
		}

		return null;
	}

	private function isPromptUpdatedAfterAssessment(array $assessment): bool
	{
		$promptUpdatedAtTimestamp = $this->resolveDateTimeTimestamp($assessment['PROMPT_UPDATED_AT'] ?? null);
		$assessmentCreatedAtTimestamp = $this->resolveDateTimeTimestamp($assessment['CREATED_AT'] ?? null);

		if ($promptUpdatedAtTimestamp === null || $assessmentCreatedAtTimestamp === null)
		{
			return false;
		}

		return $promptUpdatedAtTimestamp > $assessmentCreatedAtTimestamp;
	}

	private function resolveDateTimeTimestamp(mixed $value): ?int
	{
		$normalizedValue = $this->normalizeDateTimeValue($value);
		if ($normalizedValue === '')
		{
			return null;
		}

		$timestamp = strtotime($normalizedValue);

		return $timestamp === false ? null : $timestamp;
	}

	private function normalizeDateTimeValue(mixed $value): string
	{
		if ($value instanceof Date)
		{
			return $value->format(DATE_ATOM);
		}

		return is_scalar($value) ? (string)$value : '';
	}

	private function prepareAssessmentBlock(array $callQuality, int $index): array
	{
		$criteria = $this->prepareCriteria($callQuality);

		return [
			'blockId' => $this->makeAssessmentBlockId($callQuality, $index),
			'assessmentSettingId' => (int)($callQuality['ASSESSMENT_SETTING_ID'] ?? 0) > 0
				? (int)$callQuality['ASSESSMENT_SETTING_ID']
				: null,
			'scriptName' => (string)($callQuality['TITLE'] ?? ''),
			'recommendation' => (string)($callQuality['RECOMMENDATIONS'] ?? ''),
			'callScore' => (int)($callQuality['ASSESSMENT'] ?? 0),
			'lowerScoreBoundary' => (int)($callQuality['LOW_BORDER'] ?? 30),
			'upperScoreBoundary' => (int)($callQuality['HIGH_BORDER'] ?? 70),
			'failedCriteria' => $criteria['failedCriteria'],
			'successCriteria' => $criteria['successCriteria'],
			'unusedCriteria' => $criteria['unusedCriteria'],
			'useInRating' => $callQuality['USE_IN_RATING'] === 'Y',
			'createdAt' => $this->resolveCreatedAtTimestamp($callQuality['CREATED_AT'] ?? null),
			'isHistory' => $index > 0,
			'shouldShowReassessmentBadge' => $index === 0 && $this->isPromptUpdatedAfterAssessment($callQuality),
		];
	}

	private function loadAssessmentHistory(int $activityId): array
	{
		return AiQualityAssessmentController::getInstance()->getHistoryByActivityId($activityId);
	}

	/**
	 * @return array<int, Result|null>
	 */
	private function loadCallScoringResultsByJobIds(int $activityId, array $assessmentHistory): array
	{
		$jobIds = [];

		foreach ($assessmentHistory as $callQuality)
		{
			if (!is_array($callQuality))
			{
				continue;
			}

			$jobId = (int)($callQuality['JOB_ID'] ?? 0);
			if ($jobId > 0)
			{
				$jobIds[] = $jobId;
			}
		}

		if (empty($jobIds))
		{
			return [];
		}

		return $this->jobRepository->getAnyCallScoringResultsByJobIds($activityId, $jobIds);
	}

	private function isLegacyAssessment(array $callQuality): bool
	{
		return (int)($callQuality['CALL_SCORING_TYPE_ID'] ?? 0) === ScoreCall::TYPE_ID;
	}

	private function enrichAssessmentHistoryItem(array $callQuality, ?Result $callScoringResult): array
	{
		if ($callScoringResult === null || !$callScoringResult->isSuccess() || $callScoringResult->isPending())
		{
			$callQuality['CALL_SCORING_TYPE_ID'] = null;
			$callQuality['AI_LANGUAGE'] = null;
			$callQuality['RECOMMENDATIONS'] = '';
			$callQuality['SUMMARY'] = null;

			return $callQuality;
		}

		$payload = $callScoringResult->getPayload();
		$typeId = $callScoringResult->getTypeId();
		$criteriaScores = $typeId === ScoreCallV2::TYPE_ID ? $payload?->criteriaScores : null;
		$languageId = $callScoringResult->getLanguageId();
		$language = AiMessageProvider::getLanguageTitleByLanguageId($languageId);

		$callQuality['CALL_SCORING_TYPE_ID'] = $typeId;
		$callQuality['AI_LANGUAGE'] = AiMessageProvider::getJobLanguageMessageData($language)['html'] ?? null;
		$callQuality['RECOMMENDATIONS'] = $payload?->recommendations ?? '';
		$callQuality['SUMMARY'] = AIManager::isCallScoringV2Enabled()
			? (empty($criteriaScores) ? null : $criteriaScores)
			: (!empty($criteriaScores) ? Json::encode($criteriaScores) : null)
		;

		return $callQuality;
	}

	private function prepareLegacyAssessmentBlock(array $callQuality, int $index): ?array
	{
		$legacyAssessmentText = (string)($callQuality['RECOMMENDATIONS'] ?? '');
		if ($legacyAssessmentText === '')
		{
			return null;
		}

		return [
			'blockId' => $this->makeLegacyAssessmentBlockId($callQuality, $index),
			'blockType' => self::LEGACY_ASSESSMENT_BLOCK_ID,
			'title' => MessageProvider::getLegacyAssessmentBlockTitle(),
			'scriptName' => (string)($callQuality['TITLE'] ?? ''),
			'assessmentSettingId' => (int)($callQuality['ASSESSMENT_SETTING_ID'] ?? 0) > 0
				? (int)$callQuality['ASSESSMENT_SETTING_ID']
				: null,
			'text' => $legacyAssessmentText,
			'createdAt' => $this->resolveCreatedAtTimestamp($callQuality['CREATED_AT'] ?? null),
			'aiLanguage' => is_scalar($callQuality['AI_LANGUAGE'] ?? null) ? (string)$callQuality['AI_LANGUAGE'] : null,
			'minimized' => ($index !== 0),
		];
	}

	private function makeLegacyAssessmentBlockId(array $callQuality, int $index): string
	{
		$jobId = (int)($callQuality['JOB_ID'] ?? 0);
		if ($jobId > 0)
		{
			return self::LEGACY_ASSESSMENT_BLOCK_ID . '-' . $jobId;
		}

		$id = (int)($callQuality['ID'] ?? 0);
		if ($id > 0)
		{
			return self::LEGACY_ASSESSMENT_BLOCK_ID . '-' . $id;
		}

		return self::LEGACY_ASSESSMENT_BLOCK_ID . '-' . $index;
	}

	private function resolveCreatedAtTimestamp(mixed $value): ?int
	{
		$timestamp = $this->resolveDateTimeTimestamp($value);

		return $timestamp !== null && $timestamp > 0 ? $timestamp : null;
	}

	private function makeAssessmentBlockId(array $callQuality, int $index): string
	{
		$jobId = (int)($callQuality['JOB_ID'] ?? 0);
		if ($jobId > 0)
		{
			return self::ASSESSMENT_BLOCK_ID . '-' . $jobId;
		}

		$id = (int)($callQuality['ID'] ?? 0);
		if ($id > 0)
		{
			return self::ASSESSMENT_BLOCK_ID . '-' . $id;
		}

		return self::ASSESSMENT_BLOCK_ID . '-' . $index;
	}

	private function prepareCriteria(array $callQuality): array
	{
		$result = [
			'failedCriteria' => [],
			'successCriteria' => [],
			'unusedCriteria' => [],
		];
		$descriptionsByName = $this->loadCriterionDescriptionsByName(
			(string)($callQuality['CRITERIA_DATA'] ?? ''),
			(int)($callQuality['ASSESSMENT_SETTING_ID'] ?? 0),
		);

		$criteria = $callQuality['SUMMARY'] ?? [];
		if (is_string($criteria) && $criteria !== '')
		{
			try
			{
				$criteria = Json::decode($criteria);
			}
			catch (ArgumentException)
			{
				$criteria = [];
			}
		}

		if (!is_array($criteria))
		{
			return $result;
		}

		foreach ($criteria as $criterion)
		{
			$title = (string)($this->extractCriterionField($criterion, 'criterion_name') ?? '');
			if ($title === '')
			{
				continue;
			}

			$description = (string)($this->extractCriterionField($criterion, 'description') ?? '');
			if ($description === '')
			{
				$description = (string)($descriptionsByName[$this->normalizeCriterionName($title)] ?? '');
			}

			$summary = (string)($this->extractCriterionField($criterion, 'comment') ?? '');
			if ($summary === '')
			{
				$summary = $description;
			}

			$met = $this->extractCriterionField($criterion, 'met');

			$status = 'unused';
			if ($met === true)
			{
				$status = 'success';
			}
			elseif ($met === false)
			{
				$status = 'failure';
			}

			$item = [
				'title' => $title,
				'description' => $description,
				'application' => '',
				'summary' => $summary,
				'status' => $status,
			];

			if ($status === 'success')
			{
				$result['successCriteria'][] = $item;
			}
			elseif ($status === 'failure')
			{
				$result['failedCriteria'][] = $item;
			}
			else
			{
				$result['unusedCriteria'][] = $item;
			}
		}

		return $result;
	}

	private function loadCriterionDescriptionsByName(string $criteriaData, int $assessmentSettingsId): array
	{
		$result = [];

		if ($criteriaData !== '')
		{
			try
			{
				$rows = Json::decode($criteriaData);
			}
			catch (ArgumentException)
			{
				$rows = [];
			}

			if (is_array($rows))
			{
				foreach ($rows as $row)
				{
					if (!is_array($row))
					{
						continue;
					}

					$name = $this->normalizeCriterionName((string)($row['name'] ?? ''));
					if ($name === '')
					{
						continue;
					}

					$result[$name] = (string)($row['description'] ?? '');
				}
			}
		}

		foreach ($this->loadAssessmentCriteriaByName($assessmentSettingsId) as $name => $criterionData)
		{
			if (isset($result[$name]) && $result[$name] !== '')
			{
				continue;
			}

			$result[$name] = (string)($criterionData['description'] ?? '');
		}

		return $result;
	}

	private function normalizeCriterionName(string $name): string
	{
		return mb_strtolower(trim($name));
	}

	private function loadAssessmentCriteriaByName(int $assessmentSettingsId): array
	{
		if ($assessmentSettingsId <= 0)
		{
			return [];
		}

		if (array_key_exists($assessmentSettingsId, $this->assessmentCriteriaByNameCache))
		{
			return $this->assessmentCriteriaByNameCache[$assessmentSettingsId];
		}

		$rows = CopilotCallAssessmentCriteriaController::getInstance()->getList([
			'select' => ['TITLE', 'DESCRIPTION'],
			'filter' => ['=ASSESSMENT_ID' => $assessmentSettingsId],
		]);

		$result = [];
		foreach ($rows as $row)
		{
			$name = $this->normalizeCriterionName((string)($row['TITLE'] ?? ''));
			if ($name === '')
			{
				continue;
			}

			$result[$name] = [
				'description' => (string)($row['DESCRIPTION'] ?? ''),
			];
		}

		$this->assessmentCriteriaByNameCache[$assessmentSettingsId] = $result;

		return $this->assessmentCriteriaByNameCache[$assessmentSettingsId];
	}

	private function extractCriterionField(mixed $criterion, string $field): mixed
	{
		if (is_array($criterion))
		{
			return $criterion[$field] ?? null;
		}

		if (is_object($criterion))
		{
			return $criterion->{$field} ?? null;
		}

		return null;
	}

	private function prepareBlocks(array $data): array
	{
		$blocks = [];

		$summary = $data['summary'] ?? null;
		if (is_array($summary) && is_string($summary['summary'] ?? null) && $summary['summary'] !== '')
		{
			$blocks[] = [
				'blockId' => self::SUMMARY_BLOCK_ID,
				'blockType' => self::SUMMARY_BLOCK_ID,
				'title' => MessageProvider::getSummaryBlockTitle(),
				'text' => (string)$summary['summary'],
				'createdAt' => is_numeric($summary['createdAt'] ?? null) ? (int)$summary['createdAt'] : null,
				'aiLanguage' => is_scalar($summary['aiLanguage'] ?? null) ? (string)$summary['aiLanguage'] : null,
				'minimized' => false,
			];
		}

		$transcript = $data['transcript'] ?? null;
		if (
			is_array($transcript)
			&& is_string($transcript['transcription'] ?? null)
			&& $transcript['transcription'] !== ''
		)
		{
			$blocks[] = [
				'blockId' => self::TRANSCRIPTION_BLOCK_ID,
				'blockType' => self::TRANSCRIPTION_BLOCK_ID,
				'title' => MessageProvider::getTranscriptionBlockTitle(),
				'text' => (string)$transcript['transcription'],
				'createdAt' => is_numeric($transcript['createdAt'] ?? null) ? (int)$transcript['createdAt'] : null,
				'aiLanguage' => (string)($transcript['aiLanguage'] ?? ''),
				'minimized' => false,
			];
		}

		return $blocks;
	}

	private function prepareAnchors(array $assessmentBlocks, array $blocks): array
	{
		$anchors = [];
		$legacyAssessmentBlockId = '';

		foreach ($blocks as $block)
		{
			if (($block['blockType'] ?? null) === self::LEGACY_ASSESSMENT_BLOCK_ID)
			{
				$legacyAssessmentBlockId = (string)($block['blockId'] ?? '');
				break;
			}
		}

		if (!empty($assessmentBlocks))
		{
			$anchors[] = [
				'text' => MessageProvider::getAssessmentAnchorTitle(),
				'isActive' => true,
				'blockId' => (string)($assessmentBlocks[0]['blockId'] ?? self::ASSESSMENT_BLOCK_ID),
			];
		}
		elseif ($legacyAssessmentBlockId !== '')
		{
			$anchors[] = [
				'text' => MessageProvider::getAssessmentAnchorTitle(),
				'isActive' => true,
				'blockId' => $legacyAssessmentBlockId,
			];
		}

		foreach ($blocks as $index => $block)
		{
			if (($block['blockType'] ?? null) === self::LEGACY_ASSESSMENT_BLOCK_ID)
			{
				continue;
			}

			$anchors[] = [
				'text' => (string)($block['title'] ?? ''),
				'isActive' => empty($anchors) && $index === 0,
				'blockId' => (string)($block['blockId'] ?? ''),
			];
		}

		return $anchors;
	}
}
