<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant;

use Bitrix\Landing\AI\SiteBuilder\Dto\ModerationVerdictDto;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\RequestModerateAiSiteTopic;
use Bitrix\Landing\Copilot\Generation\Type\StepStatuses;
use Bitrix\Landing\Copilot\Model\StepsTable;

final class AiSiteGenerationHelper
{
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
	): array
	{
		$isCompleted = $status === 'completed';
		$publication = is_array($publication) ? $publication : [];
		$creationJournal = is_array($creationJournal) ? self::normalizeCreationJournal($creationJournal) : null;
		$limitError = self::normalizeLimitError($limitError);
		$recoverablePartial = !$isCompleted
			&& !empty($creationJournal['recoverablePartial'])
			&& (int)($creationJournal['siteId'] ?? 0) > 0
		;

		return [
			'jobId' => $jobId,
			'status' => $status,
			'siteId' => $isCompleted ? max(0, $siteId) : 0,
			'pageId' => $isCompleted ? max(0, $pageId) : 0,
			'message' => $message,
			'error' => $error,
			'errorType' => $limitError['type'],
			'errorText' => $limitError['text'],
			'sliderCode' => $limitError['sliderCode'],
			'metrikaStatus' => $limitError['metrikaStatus'],
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

	public static function resolveFailureMessage(Generation $generation): string
	{
		$limitError = self::extractLimitError($generation);
		if (($limitError['text'] ?? null) !== null)
		{
			return (string)$limitError['text'];
		}

		$stepMessage = self::resolveStepFailureMessage($generation);
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
		return self::resolveErroredStepId((int)($generation->getId() ?? 0)) === self::MODERATION_STEP_ID;
	}

	private static function resolveErroredStepId(int $generationId): int
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

	private static function resolveStepFailureMessage(Generation $generation): string
	{
		$generationId = (int)($generation->getId() ?? 0);
		$stepId = self::resolveErroredStepId($generationId);
		if ($stepId <= 0)
		{
			return '';
		}

		if ($stepId === self::MODERATION_STEP_ID)
		{
			// Prefer the localized verdict message (hard: static refusal, soft: reword hint)
			// saved by the moderation step over the static fallback below.
			$moderationMessage = self::resolveModerationBlockMessage($generationId, $stepId);
			if ($moderationMessage !== '')
			{
				return $moderationMessage;
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
		if ($generationId <= 0 || $stepId <= 0)
		{
			return '';
		}

		try
		{
			$requests = Request::getByGeneration($generationId, $stepId);
		}
		catch (\Throwable)
		{
			return '';
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

			return RequestModerateAiSiteTopic::resolveUserFacingMessage($verdict);
		}

		return '';
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
