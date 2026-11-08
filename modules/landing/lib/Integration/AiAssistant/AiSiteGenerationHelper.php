<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant;

use Bitrix\Landing\AI\SiteBuilder\Dto\ModerationVerdictDto;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\RequestModerateAiSiteTopic;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\StepStatuses;
use Bitrix\Landing\Copilot\Model\StepsTable;
use Bitrix\Landing\Integration\AiAssistant\Dto\FailedGenerationContextDto;
use Bitrix\Landing\Metrika;

final class AiSiteGenerationHelper
{
	public const STATUS_RUNNING = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED = 'failed';

	public const ERROR_TYPE_LIMIT = 'limit';
	public const ERROR_TYPE_MODERATION = 'moderation';
	public const ERROR_TYPE_TECHNICAL = 'technical';

	private const MODERATION_STEP_ID = 15;

	private function __construct()
	{
	}

	public static function normalizeJobId(mixed $value): string
	{
		if (!is_scalar($value))
		{
			return '';
		}

		return trim((string)$value);
	}

	public static function buildStatusResponse(
		string $jobId,
		string $status,
		int $siteId = 0,
		int $pageId = 0,
		string $message = '',
		?string $error = null,
		?array $publication = null,
		?array $creationJournal = null,
		?array $generationSummary = null,
		?array $limitError = null,
		?array $failure = null,
	): array
	{
		$isCompleted = $status === self::STATUS_COMPLETED;
		$publication = is_array($publication) ? $publication : [];
		$creationJournal = is_array($creationJournal) ? self::normalizeCreationJournal($creationJournal) : null;
		$limitError = self::normalizeLimitError($limitError);
		$failure = self::normalizeFailure($failure, $limitError);
		$recoverablePartial = !$isCompleted && self::hasRecoverableDraft($creationJournal ?? []);

		return [
			'jobId' => $jobId,
			'status' => $status,
			'siteId' => $isCompleted ? max(0, $siteId) : 0,
			'pageId' => $isCompleted ? max(0, $pageId) : 0,
			'message' => $message,
			'error' => $error,
			'errorType' => $failure['type'],
			'errorCode' => $failure['code'],
			'errorText' => $limitError['text'],
			'sliderCode' => $limitError['sliderCode'],
			'metrikaStatus' => $failure['metrikaStatus'],
			'published' => $isCompleted ? (bool)($publication['published'] ?? false) : null,
			'publicationWarning' => $isCompleted ? ($publication['warning'] ?? null) : null,
			'publication' => $isCompleted ? self::normalizePublication($publication) : null,
			'recoverablePartial' => $recoverablePartial,
			'draftSiteId' => $recoverablePartial ? (int)$creationJournal['siteId'] : 0,
			'draftPageId' => $recoverablePartial ? (int)$creationJournal['landingId'] : 0,
			'partialStage' => $recoverablePartial ? (string)$creationJournal['stage'] : '',
			'creationJournal' => $creationJournal,
			'generationSummary' => $isCompleted ? self::normalizeGenerationSummary($generationSummary) : null,
		];
	}

	/**
	 * Single source of the recoverable-draft verdict of the create flow: the answer names the draft to
	 * the agent exactly when the binding reservation is kept for it. Everything that decides about a
	 * draft asks this one expression - the status response above, the failed answer below and the
	 * binding reaction of both create entry points - so the answer and the reaction can never disagree
	 * about a draft the user is told about.
	 */
	public static function hasRecoverableDraft(array $creationJournal): bool
	{
		return !empty($creationJournal['recoverablePartial']) && (int)($creationJournal['siteId'] ?? 0) > 0;
	}

	/**
	 * Single shape of the create-flow failed answer (DTO-01): the draft journal and the limit fields
	 * always travel with it, the message tells a draft-bearing failure from a bare one, and the
	 * classification comes from the caller - a failed generation resolves it, the
	 * finished-without-identifiers branch states it outright.
	 *
	 * The form lives here, in the owner of the create status response, and nowhere else: the change
	 * flow has its own failed answer in AiSiteChangeGenerationHelper, and the two are deliberately
	 * different shapes rather than one shape with optional halves (ADR § 5).
	 *
	 * The recoverable-draft verdict is not the caller's: the answer reads the creation journal once and
	 * asks hasRecoverableDraft() about it, the very method the binding reaction asks, so the draft named
	 * to the agent is the draft the reservation is kept for.
	 */
	public static function buildFailedResponse(
		Generation $generation,
		string $jobId,
		string $error,
		array $failure,
	): array
	{
		$creationJournal = self::extractCreationJournal($generation);
		$recoverablePartial = self::hasRecoverableDraft($creationJournal);

		return self::buildStatusResponse(
			jobId: $jobId,
			status: 'failed',
			message: $recoverablePartial
				? 'Site generation failed after creating a draft site.'
				: 'Site generation failed.',
			error: $error,
			creationJournal: $creationJournal,
			limitError: self::extractLimitError($generation),
			failure: $failure,
		);
	}

	public static function normalizeLimitError(?array $limitError): array
	{
		if (!is_array($limitError))
		{
			$limitError = [];
		}

		$type = trim((string)($limitError['type'] ?? ''));
		$text = trim((string)($limitError['text'] ?? ''));
		$sliderCode = trim((string)($limitError['sliderCode'] ?? ''));
		$metrikaStatus = $limitError['metrikaStatus'] ?? null;
		$metrikaStatus = is_scalar($metrikaStatus) ? trim((string)$metrikaStatus) : null;

		if ($sliderCode === '' && $text !== '')
		{
			$sliderCode = self::extractFeaturePromoterSliderCode($text);
		}

		return [
			'type' => $type !== '' ? $type : null,
			'text' => $text !== '' ? $text : null,
			'sliderCode' => $sliderCode !== '' ? $sliderCode : null,
			'metrikaStatus' => $metrikaStatus !== '' ? $metrikaStatus : null,
		];
	}

	/**
	 * Classification of a failed generation: refusal kind, machine-readable reason code and the
	 * funnel status. Shared shape of both flows: ['type' => string, 'code' => ?string,
	 * 'metrikaStatus' => ?string]. Feed the result to buildStatusResponse(failure: ...).
	 *
	 * The failed branch of a use case resolves the errored step and the blocking verdict once and
	 * hands them over as one FailedGenerationContextDto - the pair cannot be halved. Without a
	 * context the failure is resolved here from the persisted step instead.
	 */
	public static function resolveFailure(
		Generation $generation,
		?FailedGenerationContextDto $failedContext = null,
	): array
	{
		$limitError = self::extractLimitError($generation);
		if ($limitError !== null)
		{
			return self::buildFailure(self::ERROR_TYPE_LIMIT, null, $limitError['metrikaStatus']);
		}

		$generationId = (int)($generation->getId() ?? 0);
		$stepId = $failedContext?->erroredStepId ?? self::resolveErroredStepId($generationId);
		if ($stepId === self::MODERATION_STEP_ID && self::isModerationRefusal($generation, $failedContext?->moderationVerdict))
		{
			if ($failedContext !== null)
			{
				return self::buildModerationFailureForVerdict($failedContext->moderationVerdict);
			}

			return self::buildModerationFailure($generationId, self::MODERATION_STEP_ID);
		}

		return self::buildFailure(self::ERROR_TYPE_TECHNICAL, null, Metrika\Statuses::Error->value);
	}

	/**
	 * Whether a failed moderation step is a refusal of the request rather than a failure of the check.
	 *
	 * The step id alone does not tell them apart. The moderation step refuses with restrictedRequest,
	 * but it fails like any other request step too - notSendRequest when the request never left,
	 * notCorrectResponse or dataValidation when the answer carried no readable verdict. Classifying all
	 * of them as moderation tells the user their topic was rejected by the rules of the product while
	 * the check simply never happened.
	 *
	 * A verdict that has actually been read settles it. Without one a run that failed outside the
	 * domain settles it the other way: such a failure carries no error code at all, and reading its
	 * absence as a refusal is what turned a broken database into a forbidden topic. Then the persisted
	 * error code decides, so a genuine block whose verdict row can no longer be read stays a refusal
	 * instead of turning technical. A generation with none of the three - nothing was persisted about
	 * the failure - keeps the older reading of the step id, which is the most that is known about it.
	 */
	public static function isModerationRefusal(
		Generation $generation,
		?ModerationVerdictDto $moderationVerdict,
	): bool
	{
		if ($moderationVerdict !== null)
		{
			return true;
		}

		if ($generation->isLastFailureNonDomain())
		{
			return false;
		}

		$errorCode = $generation->getData('lastErrorCode');
		if (
			!is_int($errorCode)
			&& !(is_string($errorCode) && $errorCode !== '' && preg_match('/^[0-9]+$/D', $errorCode) === 1)
		)
		{
			return true;
		}

		return (int)$errorCode === GenerationErrors::restrictedRequest->value;
	}

	/**
	 * Moderation refusal classification built from the persisted verdict of the errored step.
	 * Shared by the create and change failure resolvers, which differ only by the step id.
	 */
	public static function buildModerationFailure(int $generationId, int $stepId): array
	{
		return self::buildModerationFailureForVerdictCode(
			self::resolveModerationVerdictCode($generationId, $stepId),
		);
	}

	/**
	 * Same classification built from a verdict the caller has already read, so the failed branch
	 * reads the blocking request once instead of once per consumer. A null verdict means the verdict
	 * could not be read, exactly as in the persisted-read path.
	 */
	public static function buildModerationFailureForVerdict(?ModerationVerdictDto $verdict): array
	{
		return self::buildModerationFailureForVerdictCode(self::resolveVerdictCodeOf($verdict));
	}

	/**
	 * Mapping of a moderation verdict code ([DTO-05]) onto the classification triple: a soft block
	 * carries its own funnel status, every other refusal - an unreadable verdict included - stays on
	 * the content-policy error status. Kept apart from the persisted read so the rule itself is
	 * decidable from the code alone.
	 */
	public static function buildModerationFailureForVerdictCode(string $verdictCode): array
	{
		$metrikaStatus = $verdictCode === Errors::requestNotAllowedSoft->name
			? Metrika\Statuses::SoftBlockContentPolicy->value
			: Metrika\Statuses::ErrorContentPolicy->value
		;

		return self::buildFailure(self::ERROR_TYPE_MODERATION, $verdictCode, $metrikaStatus);
	}

	/**
	 * Canonical shape of the classification triple, shared by both flows.
	 */
	public static function buildFailure(?string $type, ?string $code, ?string $metrikaStatus): array
	{
		$type = trim((string)$type);
		$code = trim((string)$code);
		$metrikaStatus = trim((string)$metrikaStatus);

		return [
			'type' => $type !== '' ? $type : null,
			'code' => $code !== '' ? $code : null,
			'metrikaStatus' => $metrikaStatus !== '' ? $metrikaStatus : null,
		];
	}

	/**
	 * Explicit classification wins; without it the response keeps the legacy limit-only behaviour.
	 * Shared by both flows: the change helper builds the same three fields.
	 */
	public static function normalizeFailure(?array $failure, array $limitError): array
	{
		if (!is_array($failure) || !isset($failure['type']) || !is_scalar($failure['type']))
		{
			return self::buildFailure(
				(string)($limitError['type'] ?? ''),
				null,
				$limitError['metrikaStatus'],
			);
		}

		$code = $failure['code'] ?? null;
		$metrikaStatus = $failure['metrikaStatus'] ?? null;

		return self::buildFailure(
			trim((string)$failure['type']),
			is_scalar($code) ? (string)$code : null,
			is_scalar($metrikaStatus) ? (string)$metrikaStatus : null,
		);
	}

	private static function extractFeaturePromoterSliderCode(string $text): string
	{
		if (preg_match('/[?&]FEATURE_PROMOTER=([a-z0-9_\\-]+)/i', $text, $matches) !== 1)
		{
			return '';
		}

		return (string)$matches[1];
	}

	public static function extractFinalIds(Generation $generation): array
	{
		$siteData = $generation->getSiteData();

		return [
			(int)($siteData->getSiteId() ?? 0),
			(int)($siteData->getLandingId() ?? 0),
		];
	}

	public static function extractPublication(Generation $generation): array
	{
		return self::normalizePublication(CreateAiSiteState::getPublication($generation));
	}

	public static function extractCreationJournal(Generation $generation): array
	{
		return self::normalizeCreationJournal(CreateAiSiteState::getCreationJournal($generation));
	}

	public static function extractGenerationSummary(Generation $generation): ?array
	{
		$enhanced = CreateAiSiteState::getEnhancedInput($generation);

		return self::normalizeGenerationSummary([
			'title' => $enhanced['title'] ?? '',
			'description' => $enhanced['brief'] ?? '',
			'audience' => $enhanced['audience'] ?? '',
			'sections' => self::extractStructureSections(CreateAiSiteState::getStructure($generation)),
		]);
	}

	/**
	 * Reason text of a failed generation. As in resolveFailure(), the errored step and the blocking
	 * verdict may be handed over already resolved, as one FailedGenerationContextDto.
	 */
	public static function resolveFailureMessage(
		Generation $generation,
		?FailedGenerationContextDto $failedContext = null,
	): string
	{
		$limitError = self::extractLimitError($generation);
		if (($limitError['text'] ?? null) !== null)
		{
			return (string)$limitError['text'];
		}

		if ($failedContext !== null)
		{
			$stepMessage = self::resolveStepFailureMessageFor(
				$generation,
				$failedContext->erroredStepId,
				$failedContext->moderationVerdict,
			);
		}
		else
		{
			$stepMessage = self::resolveStepFailureMessage($generation);
		}

		if ($stepMessage !== '')
		{
			return $stepMessage;
		}

		$tailwindRuntime = CreateAiSiteState::getTailwindRuntime($generation);
		if (is_array($tailwindRuntime) && $tailwindRuntime !== [] && empty($tailwindRuntime['success']))
		{
			return 'Failed to initialize Tailwind runtime for the page.';
		}

		return 'Site generation failed.';
	}

	public static function extractLimitError(Generation $generation): ?array
	{
		$lastError = $generation->getData('lastError');
		if (!is_array($lastError))
		{
			return null;
		}

		$limitError = self::normalizeLimitError($lastError);
		if ($limitError['type'] !== 'limit' || $limitError['text'] === null)
		{
			return null;
		}

		return $limitError;
	}

	/**
	 * True when the generation stopped on the moderation step (forbidden topic), as opposed to a
	 * technical failure. Used by the create use case to release the draft and bump the block counter.
	 */
	public static function isModerationBlockError(Generation $generation): bool
	{
		$generationId = (int)($generation->getId() ?? 0);
		if (self::resolveErroredStepId($generationId) !== self::MODERATION_STEP_ID)
		{
			return false;
		}

		return self::isModerationRefusal(
			$generation,
			self::resolveBlockingModerationVerdict($generationId, self::MODERATION_STEP_ID),
		);
	}

	/**
	 * The reason a generation was stopped by topic moderation, or null when it stopped for any other
	 * reason. Answers from the id alone, for a caller that holds the failure of a generation rather
	 * than the generation itself.
	 *
	 * An empty string means the block is there but carries no verdict text of its own: the caller
	 * still knows it was the topic, and says so in its own words.
	 */
	public static function resolveModerationBlockReason(int $generationId): ?string
	{
		if (self::resolveErroredStepId($generationId) !== self::MODERATION_STEP_ID)
		{
			return null;
		}

		return self::resolveModerationBlockMessage($generationId, self::MODERATION_STEP_ID);
	}

	/**
	 * Same predicate as isModerationBlockError(), decided from an errored step id the caller has
	 * already resolved: true only for the create-flow moderation step (DTO-03), false for every
	 * other step and for the "no errored step" id 0. Reads nothing, so the failed branch classifies
	 * without repeating the persisted step query.
	 */
	public static function isModerationBlockStepId(int $erroredStepId): bool
	{
		return $erroredStepId === self::MODERATION_STEP_ID;
	}

	/**
	 * Same predicate as isModerationBlockStepId(), but over a step that has actually refused the
	 * request rather than failed its check. A moderation step that could not reach the AI service is
	 * not a block of the draft: counting it as one spends a consecutive-block attempt of a chat that
	 * was never told anything about its topic.
	 */
	public static function isModerationBlockRefusal(
		Generation $generation,
		int $erroredStepId,
		?ModerationVerdictDto $moderationVerdict,
	): bool
	{
		return self::isModerationBlockStepId($erroredStepId)
			&& self::isModerationRefusal($generation, $moderationVerdict);
	}

	/**
	 * Step id of the last errored step of the generation, or 0 if there is none.
	 * Single source of the query for both the create and the change flow.
	 */
	public static function resolveErroredStepId(int $generationId): int
	{
		if ($generationId <= 0)
		{
			return 0;
		}

		try
		{
			$row = StepsTable::query()
				->setSelect(['STEP_ID'])
				->where('GENERATION_ID', '=', $generationId)
				->where('STATUS', '=', StepStatuses::Error->value)
				->setOrder(['ID' => 'DESC'])
				->setLimit(1)
				->exec()
				->fetch()
			;
		}
		catch (\Throwable)
		{
			return 0;
		}

		return is_array($row) ? (int)($row['STEP_ID'] ?? 0) : 0;
	}

	/**
	 * Step message of a generation whose errored step is not known yet: resolves the step and the
	 * blocking verdict itself, then hands both to the shared message rule.
	 */
	private static function resolveStepFailureMessage(Generation $generation): string
	{
		$generationId = (int)($generation->getId() ?? 0);
		$stepId = self::resolveErroredStepId($generationId);

		return self::resolveStepFailureMessageFor(
			$generation,
			$stepId,
			self::resolveModerationVerdictForErroredStep($generationId, $stepId),
		);
	}

	/**
	 * Message rule over an errored step and its blocking verdict, both already read by the caller.
	 */
	private static function resolveStepFailureMessageFor(
		Generation $generation,
		int $stepId,
		?ModerationVerdictDto $moderationVerdict,
	): string
	{
		if ($stepId <= 0)
		{
			return '';
		}

		if ($stepId === self::MODERATION_STEP_ID)
		{
			// Prefer the localized verdict message (hard: static refusal, soft: reword hint)
			// saved by the moderation step over the static fallback below.
			$moderationMessage = self::resolveModerationBlockMessageOf($moderationVerdict);
			if ($moderationMessage !== '')
			{
				return $moderationMessage;
			}

			// The step is also the one that fails when the check could not be made at all. Its static
			// wording below speaks of a topic that is not allowed, which would be a false statement
			// about the request the user actually sent.
			if (!self::isModerationRefusal($generation, $moderationVerdict))
			{
				return 'Failed to check the requested topic.';
			}
		}

		return match ($stepId)
		{
			10 => 'Failed to initialize site generation context.',
			15 => 'The requested topic is not allowed for AI site generation.',
			20 => 'Failed to enhance user input for site generation.',
			30 => 'Failed to prepare site data for generation.',
			40 => 'Failed to generate preview image.',
			50 => 'Failed to generate page structure.',
			60 => 'Failed to generate HTML blocks.',
			70 => 'Failed to improve generated HTML blocks.',
			80 => 'Failed to create site and landing.',
			90 => 'Failed to initialize Tailwind runtime for the page.',
			100 => 'Failed to prepare generated HTML markup.',
			110 => 'Failed to prepare CRM forms in generated HTML.',
			120 => 'Failed to add generated blocks to the landing.',
			130 => 'Failed to synchronize generated fonts.',
			140 => 'Failed to prepare created block markup.',
			150 => 'Failed to generate images for created blocks.',
			160 => 'Failed to publish generated site.',
			default => '',
		};
	}

	/**
	 * Localized user-facing message derived from the persisted moderation verdict (RESULT) of the
	 * errored request, or empty string if none. Shared by the create and change failure resolvers.
	 */
	public static function resolveModerationBlockMessage(int $generationId, int $stepId): string
	{
		return self::resolveModerationBlockMessageOf(
			self::resolveBlockingModerationVerdict($generationId, $stepId),
		);
	}

	/**
	 * Same message built from a verdict the caller has already read.
	 */
	public static function resolveModerationBlockMessageOf(?ModerationVerdictDto $verdict): string
	{
		return $verdict === null ? '' : RequestModerateAiSiteTopic::resolveUserFacingMessage($verdict);
	}

	/**
	 * Machine-readable code of the persisted moderation verdict (DTO-05), or empty string when the
	 * verdict could not be read. Counterpart of resolveModerationBlockMessage(): the user-facing
	 * text never carries this code.
	 */
	public static function resolveModerationVerdictCode(int $generationId, int $stepId): string
	{
		return self::resolveVerdictCodeOf(self::resolveBlockingModerationVerdict($generationId, $stepId));
	}

	private static function resolveVerdictCodeOf(?ModerationVerdictDto $verdict): string
	{
		return $verdict === null ? '' : RequestModerateAiSiteTopic::resolveVerdictCode($verdict);
	}

	/**
	 * Blocking verdict of the errored step, or null when the failure is not a moderation block.
	 * Entry point of the failed branch: read once here, then hand the result to the text and the
	 * code consumer instead of letting each of them repeat the persisted read.
	 */
	public static function resolveModerationVerdictForErroredStep(
		int $generationId,
		int $erroredStepId,
	): ?ModerationVerdictDto
	{
		if ($erroredStepId !== self::MODERATION_STEP_ID)
		{
			return null;
		}

		return self::resolveBlockingModerationVerdict($generationId, $erroredStepId);
	}

	public static function resolveBlockingModerationVerdict(int $generationId, int $stepId): ?ModerationVerdictDto
	{
		if ($generationId <= 0 || $stepId <= 0)
		{
			return null;
		}

		try
		{
			$requests = Request::getByGeneration($generationId, $stepId);
		}
		catch (\Throwable)
		{
			return null;
		}

		foreach ($requests as $request)
		{
			$result = $request->getResult();
			if (!is_array($result))
			{
				continue;
			}

			$verdict = ModerationVerdictDto::fromArray($result);
			if ($verdict === null || $verdict->isAllowed())
			{
				continue;
			}

			return $verdict;
		}

		return null;
	}

	private static function normalizePublication(array $publication): array
	{
		return [
			'attempted' => !empty($publication['attempted']),
			'published' => !empty($publication['published']),
			'warning' => isset($publication['warning']) && is_scalar($publication['warning'])
				? (string)$publication['warning']
				: null,
			'errorCode' => isset($publication['errorCode']) && is_scalar($publication['errorCode'])
				? (string)$publication['errorCode']
				: null,
		];
	}

	private static function normalizeCreationJournal(array $journal): array
	{
		return [
			'siteId' => max(0, (int)($journal['siteId'] ?? 0)),
			'landingId' => max(0, (int)($journal['landingId'] ?? 0)),
			'stage' => isset($journal['stage']) && is_scalar($journal['stage'])
				? (string)$journal['stage']
				: '',
			'lastSuccessfulStep' => max(0, (int)($journal['lastSuccessfulStep'] ?? 0)),
			'recoverablePartial' => !empty($journal['recoverablePartial']),
			'createdBlockIds' => self::normalizeIds($journal['createdBlockIds'] ?? []),
			'createdRepoIds' => self::normalizeIds($journal['createdRepoIds'] ?? []),
			'blocks' => is_array($journal['blocks'] ?? null) ? $journal['blocks'] : [],
		];
	}

	private static function normalizeGenerationSummary(?array $summary): ?array
	{
		if (!is_array($summary))
		{
			return null;
		}

		$normalized = [
			'title' => self::normalizeTextValue($summary['title'] ?? ''),
			'description' => self::normalizeTextValue($summary['description'] ?? ''),
			'audience' => self::normalizeTextValue($summary['audience'] ?? ''),
			'sections' => self::normalizeTextList($summary['sections'] ?? []),
		];

		if (
			$normalized['title'] === ''
			&& $normalized['description'] === ''
			&& $normalized['audience'] === ''
			&& $normalized['sections'] === []
		)
		{
			return null;
		}

		return $normalized;
	}

	private static function extractStructureSections(array $structure): array
	{
		$sections = [];
		foreach ($structure as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$sections[] = $item['navigation_title'] ?? '';
		}

		return self::normalizeTextList($sections);
	}

	private static function normalizeTextList(mixed $items): array
	{
		if (!is_array($items))
		{
			return [];
		}

		$result = [];
		$seen = [];
		foreach ($items as $item)
		{
			$item = self::normalizeTextValue($item);
			if ($item === '')
			{
				continue;
			}

			$key = mb_strtolower($item);
			if (isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = true;
			$result[] = $item;
		}

		return $result;
	}

	private static function normalizeTextValue(mixed $value): string
	{
		if (!is_scalar($value))
		{
			return '';
		}

		$value = preg_replace('/\s+/u', ' ', (string)$value);

		return trim(is_string($value) ? $value : '');
	}

	private static function normalizeIds(mixed $ids): array
	{
		if (!is_array($ids))
		{
			return [];
		}

		$result = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$result[] = $id;
			}
		}

		return array_values(array_unique($result));
	}
}
