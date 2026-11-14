<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\AiMessageProvider;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation\PresentationResolver;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ActivityBadgeCleaner;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\CallRecordDataProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\JobIdNormalizer;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\MessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SettingsProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SummaryDataProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\TranscriptMarkupMapper;
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
	private readonly ActivityBadgeCleaner $activityBadgeCleaner;

	public function __construct(
		private readonly PresentationResolver $presentationResolver,
		private readonly SummaryDataProvider $summaryDataProvider,
		private readonly JobRepository $jobRepository,
		private readonly SettingsProvider $settingsProvider,
		?CallRecordDataProvider $callRecordDataProvider = null,
		?ActivityBadgeCleaner $activityBadgeCleaner = null,
	)
	{
		$this->callRecordDataProvider = $callRecordDataProvider ?? new CallRecordDataProvider();
		$this->activityBadgeCleaner = $activityBadgeCleaner ?? new ActivityBadgeCleaner();
	}

	public function getCode(): ScenarioCode
	{
		return ScenarioCode::CALL_ASSESSMENT;
	}

	public function build(ActivityContext $context): Result
	{
		$result = new Result();
		if (($context->activity['PROVIDER_ID'] ?? null) !== Call::getId())
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$data = [
			'aiDisclaimer' => AiMessageProvider::getAiDisclaimerData()['html'] ?? null,
			'callRecord' => $this->callRecordDataProvider->load($context->activity),
		];

		$transcriptResult = $this->summaryDataProvider->loadTranscript($context->request->activityId);
		$data['transcript'] = $transcriptResult->isSuccess()
			? ($transcriptResult->getData()['payload'] ?? null)
			: null;
		if (is_array($data['transcript']))
		{
			$data['transcript']['jobId'] = $transcriptResult->getData()['jobId'] ?? null;
			$data['transcript']['createdAt'] = $transcriptResult->getData()['createdAt'] ?? null;
			$data['transcript']['aiLanguage'] = $this->summaryDataProvider->getTranscriptAiLanguage(
				$context->request->activityId,
			);
		}

		$data['summaryHistory'] = array_values(array_filter(
			$this->summaryDataProvider->loadSummaryHistory($context->request->activityId),
			static fn(array $summary): bool => (int)($summary['originalJobId'] ?? $summary['jobId'] ?? 0) > 0
				&& (string)($summary['summary'] ?? '') !== '',
		));

		$data['summary'] = null;
		if ($data['summaryHistory'] === [])
		{
			// Summary is optional: legacy (v1) assessments and portals with summarization disabled have no job.
			$summaryResult = $this->summaryDataProvider->loadSummary($context->request->activityId);
			$data['summary'] = $summaryResult->isSuccess()
				? ($summaryResult->getData()['payload'] ?? null)
				: null;
			if (is_array($data['summary']))
			{
				$data['summary']['jobId'] = $summaryResult->getData()['jobId'] ?? null;
				$data['summary']['createdAt'] = $summaryResult->getData()['createdAt'] ?? null;
				$data['summary']['aiLanguage'] = $this->summaryDataProvider->getSummaryAiLanguage(
					$context->request->activityId,
				);
			}
		}

		$assessmentHistory = $this->loadAssessmentHistory($context->request->activityId);
		$assessmentContent = $this->prepareAssessmentContent($context->request->activityId, $assessmentHistory);

		$assessmentBlocks = $assessmentContent['assessmentBlocks'];
		$blocks = array_merge($assessmentContent['blocks'], $this->prepareBlocks($data));
		if ($assessmentBlocks === [] && $blocks === [])
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$result->setData([
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

		if (
			($context->activity['PROVIDER_ID'] ?? null) === Call::getId()
			&& in_array($context->request->targetBlock, [self::SUMMARY_BLOCK_ID, self::TRANSCRIPTION_BLOCK_ID], true)
		)
		{
			$this->activityBadgeCleaner->removeByOwner($context);
		}

		return $result;
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
		$theme = $data['summaryHistory'][0]['theme'] ?? $data['summary']['data']['theme'] ?? null;

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
			$assessmentHistory[$index] = $callQuality;
		}

		$this->loadAssessmentCriteriaByHistory($assessmentHistory);

		foreach ($assessmentHistory as $index => $callQuality)
		{
			if (!is_array($callQuality))
			{
				continue;
			}

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
		$jobId = JobIdNormalizer::normalize($callQuality['JOB_ID'] ?? null);
		$criteria = $this->prepareCriteria($callQuality);

		return [
			'blockId' => $this->makeAssessmentBlockId($callQuality, $index),
			'jobId' => $jobId,
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
			: (!empty($criteriaScores) ? Json::encode($criteriaScores) : null);

		return $callQuality;
	}

	private function prepareLegacyAssessmentBlock(array $callQuality, int $index): ?array
	{
		$jobId = JobIdNormalizer::normalize($callQuality['JOB_ID'] ?? null);
		$legacyAssessmentText = (string)($callQuality['RECOMMENDATIONS'] ?? '');
		if ($legacyAssessmentText === '')
		{
			return null;
		}

		return [
			'blockId' => $this->makeLegacyAssessmentBlockId($callQuality, $index),
			'jobId' => $jobId,
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

		foreach ($this->assessmentCriteriaByNameCache[$assessmentSettingsId] ?? [] as $name => $criterionData)
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

	private function loadAssessmentCriteriaByHistory(array $assessmentHistory): void
	{
		$assessmentSettingsIds = [];
		foreach ($assessmentHistory as $callQuality)
		{
			if (!is_array($callQuality) || $this->isLegacyAssessment($callQuality))
			{
				continue;
			}

			$id = (int)($callQuality['ASSESSMENT_SETTING_ID'] ?? 0);
			if ($id > 0 && !array_key_exists($id, $this->assessmentCriteriaByNameCache))
			{
				$assessmentSettingsIds[$id] = $id;
			}
		}

		if (empty($assessmentSettingsIds))
		{
			return;
		}

		$rows = CopilotCallAssessmentCriteriaController::getInstance()->getList([
			'select' => ['ASSESSMENT_ID', 'TITLE', 'DESCRIPTION'],
			'filter' => ['@ASSESSMENT_ID' => array_values($assessmentSettingsIds)],
		]);

		foreach ($assessmentSettingsIds as $id)
		{
			$this->assessmentCriteriaByNameCache[$id] = [];
		}

		foreach ($rows as $row)
		{
			$name = $this->normalizeCriterionName((string)($row['TITLE'] ?? ''));
			if ($name === '')
			{
				continue;
			}

			$this->assessmentCriteriaByNameCache[(int)$row['ASSESSMENT_ID']][$name] = [
				'description' => (string)($row['DESCRIPTION'] ?? ''),
			];
		}
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

		foreach ($data['summaryHistory'] ?? [] as $index => $summary)
		{
			$jobId = $summary['originalJobId'] ?? $summary['jobId'];
			$normalizedJobId = JobIdNormalizer::normalize($summary['jobId'] ?? null);
			$blocks[] = [
				'blockId' => $index === 0 ? self::SUMMARY_BLOCK_ID : self::SUMMARY_BLOCK_ID . '-' . $jobId,
				'jobId' => $normalizedJobId,
				'blockType' => self::SUMMARY_BLOCK_ID,
				'title' => MessageProvider::getSummaryBlockTitle(),
				'text' => $summary['summary'],
				'createdAt' => is_numeric($summary['createdAt'] ?? null) ? (int)$summary['createdAt'] : null,
				'aiLanguage' => is_scalar($summary['aiLanguage'] ?? null) ? (string)$summary['aiLanguage'] : null,
				'minimized' => $index > 0,
			];
		}

		$summary = $data['summary'] ?? null;
		if ($blocks === [] && is_array($summary) && is_string($summary['summary'] ?? null) && $summary['summary'] !== '')
		{
			$blocks[] = [
				'blockId' => self::SUMMARY_BLOCK_ID,
				'jobId' => JobIdNormalizer::normalize($summary['jobId'] ?? null),
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
				'jobId' => JobIdNormalizer::normalize($transcript['jobId'] ?? null),
				'blockType' => self::TRANSCRIPTION_BLOCK_ID,
				'title' => MessageProvider::getTranscriptionBlockTitle(),
				'text' => (string)$transcript['transcription'],
				'createdAt' => is_numeric($transcript['createdAt'] ?? null) ? (int)$transcript['createdAt'] : null,
				'aiLanguage' => (string)($transcript['aiLanguage'] ?? ''),
				'minimized' => false,
			]
				+ TranscriptMarkupMapper::map($transcript);
		}

		return $blocks;
	}

	private function prepareAnchors(array $assessmentBlocks, array $blocks): array
	{
		$anchors = [];
		$legacyAssessmentBlockId = '';
		$hasSummaryAnchor = false;

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
			if (($block['blockType'] ?? null) === self::SUMMARY_BLOCK_ID)
			{
				if ($hasSummaryAnchor)
				{
					continue;
				}

				$hasSummaryAnchor = true;
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
