<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant;

use Bitrix\Landing\AI\SiteBuilder\Dto\ModerationVerdictDto;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteErrorMessages;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteSelectedElement;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteSelectedElementErrorMessages;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Integration\AiAssistant\Dto\FailedGenerationContextDto;
use Bitrix\Landing\Internals\LandingTable;
use Bitrix\Landing\Metrika;

final class AiSiteChangeGenerationHelper
{
	private const MODERATION_STEP_ID = 25;

	private function __construct()
	{
	}

	public static function normalizeJobId(mixed $value): string
	{
		return AiSiteGenerationHelper::normalizeJobId($value);
	}

	public static function buildStatusResponse(
		string $jobId,
		string $status,
		int $pageId = 0,
		string $message = '',
		?string $error = null,
		array $changeResult = [],
		int $siteId = 0,
		?array $changeSummary = null,
		?array $limitError = null,
		?array $failure = null,
	): array
	{
		$changeResult = self::normalizeChangeResult($changeResult);
		$isCompleted = $status === AiSiteGenerationHelper::STATUS_COMPLETED;
		$limitError = AiSiteGenerationHelper::normalizeLimitError($limitError);
		$failure = AiSiteGenerationHelper::normalizeFailure($failure, $limitError);

		return [
			'jobId' => $jobId,
			'status' => $status,
			'siteId' => max(0, $siteId),
			'pageId' => max(0, $pageId),
			'message' => $message,
			'error' => $error,
			'errorType' => $failure['type'],
			'errorCode' => $failure['code'],
			'errorText' => $limitError['text'],
			'sliderCode' => $limitError['sliderCode'],
			'metrikaStatus' => $failure['metrikaStatus'],
			'changeResult' => $changeResult,
			'changedCount' => count($changeResult['changedIds']),
			'updatedCount' => count($changeResult['updatedIds']),
			'createdCount' => count($changeResult['createdIds']),
			'deletedCount' => count($changeResult['deletedIds']),
			'movedCount' => count($changeResult['movedIds']),
			'skippedCount' => count($changeResult['skippedIds']),
			'changeSummary' => $isCompleted ? self::normalizeChangeSummary($changeSummary) : null,
		];
	}

	/**
	 * Single shape of the change-flow failed answer (DTO-02): the real identifiers and the counters of
	 * the changes already applied stay in the answer; the classification comes from the caller.
	 *
	 * The form lives here, in the owner of the change status response, and nowhere else: the create
	 * flow has its own failed answer in AiSiteGenerationHelper, carrying the draft fields this one has
	 * no notion of. The two shapes stay two methods on purpose (ADR § 5).
	 */
	public static function buildFailedResponse(
		Generation $generation,
		string $jobId,
		int $pageId,
		int $siteId,
		string $error,
		array $failure,
	): array
	{
		return self::buildStatusResponse(
			jobId: $jobId,
			status: 'failed',
			pageId: $pageId,
			message: 'Page change generation failed.',
			error: $error,
			changeResult: self::extractResultState($generation),
			siteId: $siteId,
			limitError: AiSiteGenerationHelper::extractLimitError($generation),
			failure: $failure,
		);
	}

	public static function extractSiteId(Generation $generation): int
	{
		return max(0, (int)($generation->getSiteData()->getSiteId() ?? 0));
	}

	/**
	 * Site of a known page. The site data of a change generation is filled at step 40, so a generation
	 * that failed earlier (moderation is step 25, a technical failure may happen before it) knows its
	 * page but not its site, while DTO-02 promises both of them real. The page is the answer to that:
	 * a change generation never leaves the page it was started for.
	 *
	 * One narrow row read by primary key: an answer path must not pay for a Landing instance, which
	 * would load every block of the page and update its version only to hand back a site id. A failed
	 * read answers zero, as the errored-step read does: a missing site id degrades the answer, it
	 * must not replace the failure with an exception.
	 */
	public static function resolveSiteIdByPageId(int $pageId): int
	{
		if ($pageId <= 0)
		{
			return 0;
		}

		try
		{
			$row = LandingTable::query()
				->setSelect(['SITE_ID'])
				->where('ID', '=', $pageId)
				->setLimit(1)
				->exec()
				->fetch()
			;
		}
		catch (\Throwable)
		{
			return 0;
		}

		return max(0, (int)($row['SITE_ID'] ?? 0));
	}

	public static function extractPageId(Generation $generation): int
	{
		$pageId = (int)($generation->getSiteData()->getLandingId() ?? 0);
		if ($pageId > 0)
		{
			return $pageId;
		}

		return ChangeAiSiteState::resolveLandingId($generation);
	}

	public static function extractResultState(Generation $generation): array
	{
		// The stored result is written once per replace cycle, the applied operations after every single
		// one of them. A generation that failed in the middle of a cycle has operations the result has
		// not seen, so the answer is reconciled with them: otherwise the counters report zero for a page
		// whose blocks have already been changed and the assistant never warns about it.
		$result = ChangeAiSiteState::reconcileResultWithAppliedOperations(
			ChangeAiSiteState::getResult($generation),
			ChangeAiSiteState::getAppliedOperations($generation),
		);
		$result['appliedOperations'] = ChangeAiSiteState::getPublicAppliedOperations($generation);

		return $result;
	}

	public static function extractChangeSummary(Generation $generation): ?array
	{
		$items = [];
		$seen = [];
		foreach (ChangeAiSiteState::getPublicAppliedOperations($generation, true) as $operation)
		{
			if (!is_array($operation))
			{
				continue;
			}

			$actionId = self::normalizeText((string)($operation['actionId'] ?? ''));
			$type = self::normalizeOperationType($operation['type'] ?? '');
			$blockId = max(0, (int)($operation['blockId'] ?? 0));
			$description = $actionId !== ''
				? ChangeAiSiteState::resolveActionInstruction($generation, $actionId)
				: ''
			;
			if ($description === '' && $blockId > 0)
			{
				$description = ChangeAiSiteState::resolveBlockInstruction($generation, $blockId);
			}
			$description = self::normalizeText($description);

			if ($type === '' && $description === '' && $blockId <= 0)
			{
				continue;
			}

			$key = $type . '|' . mb_strtolower($description) . '|' . $blockId;
			if (isset($seen[$key]))
			{
				continue;
			}
			$seen[$key] = true;

			$items[] = [
				'type' => $type,
				'description' => $description,
				'blockId' => $blockId,
			];
			if (count($items) >= 10)
			{
				break;
			}
		}

		return self::normalizeChangeSummary(['items' => $items]);
	}

	/**
	 * Reason text of a failed change generation. The errored step and the blocking verdict may be
	 * handed over already resolved by the failed branch of a use case, as one
	 * FailedGenerationContextDto.
	 */
	public static function resolveFailureMessage(
		Generation $generation,
		?FailedGenerationContextDto $failedContext = null,
	): string
	{
		$limitError = AiSiteGenerationHelper::extractLimitError($generation);
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

		$reason = self::resolveSelectionReason($generation);
		if ($reason !== '')
		{
			return $reason;
		}

		return 'Page change generation failed.';
	}

	/**
	 * Classification of a failed change generation (DTO-03/DTO-05), in the shape accepted by
	 * buildStatusResponse(failure: ...). Mirrors the create resolver, differing by the moderation step.
	 *
	 * As in resolveFailureMessage(), the errored step and the blocking verdict may be handed over
	 * already resolved, as one FailedGenerationContextDto.
	 */
	public static function resolveFailure(
		Generation $generation,
		?FailedGenerationContextDto $failedContext = null,
	): array
	{
		$limitError = AiSiteGenerationHelper::extractLimitError($generation);
		if ($limitError !== null)
		{
			return AiSiteGenerationHelper::buildFailure(
				AiSiteGenerationHelper::ERROR_TYPE_LIMIT,
				null,
				$limitError['metrikaStatus'],
			);
		}

		$stepId = $failedContext?->erroredStepId
			?? AiSiteGenerationHelper::resolveErroredStepId((int)($generation->getId() ?? 0))
		;
		// The step id says which step failed, not why. A moderation step that could not perform its
		// check at all is a technical failure, and the shared rule is what tells the two apart.
		if (
			$stepId === self::MODERATION_STEP_ID
			&& AiSiteGenerationHelper::isModerationRefusal($generation, $failedContext?->moderationVerdict)
		)
		{
			if ($failedContext !== null)
			{
				return AiSiteGenerationHelper::buildModerationFailureForVerdict($failedContext->moderationVerdict);
			}

			return AiSiteGenerationHelper::buildModerationFailure(
				(int)($generation->getId() ?? 0),
				self::MODERATION_STEP_ID,
			);
		}

		return AiSiteGenerationHelper::buildFailure(
			AiSiteGenerationHelper::ERROR_TYPE_TECHNICAL,
			null,
			Metrika\Statuses::Error->value,
		);
	}

	/**
	 * Blocking verdict of the errored step of a change generation, or null when the failure is not a
	 * moderation block. Read once by the failed branch and handed to both the text and the code.
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

		return AiSiteGenerationHelper::resolveBlockingModerationVerdict($generationId, $erroredStepId);
	}

	/**
	 * Step message of a change generation whose errored step is not known yet: resolves the step and
	 * the blocking verdict itself, then hands both to the shared message rule.
	 */
	private static function resolveStepFailureMessage(Generation $generation): string
	{
		$generationId = (int)($generation->getId() ?? 0);
		$stepId = AiSiteGenerationHelper::resolveErroredStepId($generationId);

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
			// saved by the moderation step over the static map fallback.
			$moderationMessage = AiSiteGenerationHelper::resolveModerationBlockMessageOf($moderationVerdict);
			if ($moderationMessage !== '')
			{
				return $moderationMessage;
			}

			// The step is also the one that fails when the check could not be made at all. The static
			// wording of this step speaks of a change that is not allowed, which would be a false
			// statement about the request the user actually sent. Only the map of the whole-page
			// scenario has that wording: the selected-element scenario has no moderation step at all,
			// and the branch is simply never reached for it.
			if (!AiSiteGenerationHelper::isModerationRefusal($generation, $moderationVerdict))
			{
				return 'Failed to check the requested change.';
			}
		}

		return self::resolveErrorMessage($generation, $stepId);
	}

	private static function resolveErrorMessage(Generation $generation, int $stepId): string
	{
		$scenario = $generation->getScenario();
		if ($scenario instanceof ChangeAiSiteSelectedElement)
		{
			return ChangeAiSiteSelectedElementErrorMessages::resolve(
				$stepId,
				ChangeAiSiteState::getSelectionDebug($generation),
			);
		}

		return ChangeAiSiteErrorMessages::resolve(
			$stepId,
			ChangeAiSiteState::getSelectionDebug($generation),
		);
	}

	/**
	 * Fallback reason of a failed change generation whose errored step gave no message: the wording and
	 * the prefix are asked of the messages of the scenario that failed rather than rebuilt here. The two
	 * scenarios write different things into the same selection debug - the whole-page one a ready
	 * sentence, the selected-element one service codes - so each of them must word its own reason,
	 * otherwise this fallback hands the codes straight to the user.
	 */
	private static function resolveSelectionReason(Generation $generation): string
	{
		$selectionDebug = ChangeAiSiteState::getSelectionDebug($generation);
		if ($generation->getScenario() instanceof ChangeAiSiteSelectedElement)
		{
			$reason = ChangeAiSiteSelectedElementErrorMessages::formatSelectionReason($selectionDebug);

			return $reason === ''
				? ''
				: ChangeAiSiteSelectedElementErrorMessages::SELECTION_FAILURE_PREFIX . $reason
			;
		}

		$reason = ChangeAiSiteErrorMessages::formatSelectionReason($selectionDebug);

		return $reason === '' ? '' : ChangeAiSiteErrorMessages::SELECTION_FAILURE_PREFIX . $reason;
	}

	private static function normalizeChangeResult(array $changeResult): array
	{
		$updatedIds = self::normalizeIdList($changeResult['updatedIds'] ?? null);
		$createdIds = self::normalizeIdList($changeResult['createdIds'] ?? null);
		$deletedIds = self::normalizeIdList($changeResult['deletedIds'] ?? null);
		$movedIds = self::normalizeIdList($changeResult['movedIds'] ?? null);
		$changedIds = self::normalizeIdList(array_merge(
			self::normalizeIdList($changeResult['changedIds'] ?? null),
			$updatedIds,
			$createdIds,
			$deletedIds,
			$movedIds,
		));

		return [
			'changedIds' => $changedIds,
			'updatedIds' => $updatedIds,
			'createdIds' => $createdIds,
			'deletedIds' => $deletedIds,
			'movedIds' => $movedIds,
			'moveResults' => is_array($changeResult['moveResults'] ?? null) ? $changeResult['moveResults'] : [],
			'skippedIds' => self::normalizeIdList($changeResult['skippedIds'] ?? null),
			'skippedReasons' => is_array($changeResult['skippedReasons'] ?? null) ? $changeResult['skippedReasons'] : [],
			'appliedOperations' => is_array($changeResult['appliedOperations'] ?? null) ? array_values($changeResult['appliedOperations']) : [],
		];
	}

	private static function normalizeChangeSummary(?array $summary): ?array
	{
		if (!is_array($summary))
		{
			return null;
		}

		$items = is_array($summary['items'] ?? null) ? $summary['items'] : [];
		$normalized = [];
		$seen = [];
		foreach ($items as $item)
		{
			if (!is_array($item))
			{
				$description = self::normalizeText((string)$item);
				$type = '';
				$blockId = 0;
			}
			else
			{
				$description = self::normalizeText((string)($item['description'] ?? ''));
				$type = self::normalizeOperationType($item['type'] ?? '');
				$blockId = max(0, (int)($item['blockId'] ?? 0));
			}

			if ($description === '' && $type === '' && $blockId <= 0)
			{
				continue;
			}

			$key = $type . '|' . mb_strtolower($description) . '|' . $blockId;
			if (isset($seen[$key]))
			{
				continue;
			}
			$seen[$key] = true;

			$normalized[] = [
				'type' => $type,
				'description' => $description,
				'blockId' => $blockId,
			];
			if (count($normalized) >= 10)
			{
				break;
			}
		}

		return $normalized === [] ? null : ['items' => $normalized];
	}

	private static function normalizeOperationType(mixed $type): string
	{
		$type = mb_strtolower(self::normalizeText((string)$type));

		return in_array($type, ['update_block', 'add_block', 'delete_block', 'move_block'], true) ? $type : '';
	}

	private static function normalizeText(string $value): string
	{
		return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
	}

	private static function normalizeIdList(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$normalized = array_filter(
			array_map('intval', $value),
			static fn(int $id): bool => $id > 0,
		);

		return array_values(array_unique($normalized));
	}
}
