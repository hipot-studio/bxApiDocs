<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteErrorMessages;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\StepStatuses;
use Bitrix\Landing\Copilot\Model\StepsTable;

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
	): array
	{
		$changeResult = self::normalizeChangeResult($changeResult);
		$isCompleted = $status === 'completed';
		$limitError = AiSiteGenerationHelper::normalizeLimitError($limitError);

		return [
			'jobId' => $jobId,
			'status' => $status,
			'siteId' => max(0, $siteId),
			'pageId' => max(0, $pageId),
			'message' => $message,
			'error' => $error,
			'errorType' => $limitError['type'],
			'errorText' => $limitError['text'],
			'sliderCode' => $limitError['sliderCode'],
			'metrikaStatus' => $limitError['metrikaStatus'],
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

	public static function extractSiteId(Generation $generation): int
	{
		return max(0, (int)($generation->getSiteData()->getSiteId() ?? 0));
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
		$result = ChangeAiSiteState::getResult($generation);
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

	public static function resolveFailureMessage(Generation $generation): string
	{
		$limitError = AiSiteGenerationHelper::extractLimitError($generation);
		if (($limitError['text'] ?? null) !== null)
		{
			return (string)$limitError['text'];
		}

		$stepMessage = self::resolveStepFailureMessage($generation);
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

	private static function resolveStepFailureMessage(Generation $generation): string
	{
		$generationId = (int)($generation->getId() ?? 0);
		if ($generationId <= 0)
		{
			return '';
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
			return '';
		}
		$stepId = is_array($row) ? (int)($row['STEP_ID'] ?? 0) : 0;
		if ($stepId <= 0)
		{
			return '';
		}

		if ($stepId === self::MODERATION_STEP_ID)
		{
			// Prefer the localized verdict message (hard: static refusal, soft: reword hint)
			// saved by the moderation step over the static map fallback.
			$moderationMessage = AiSiteGenerationHelper::resolveModerationBlockMessage($generationId, $stepId);
			if ($moderationMessage !== '')
			{
				return $moderationMessage;
			}
		}

		return ChangeAiSiteErrorMessages::resolve($stepId);
	}

	private static function resolveSelectionReason(Generation $generation): string
	{
		$selectionDebug = ChangeAiSiteState::getSelectionDebug($generation);
		$reason = trim((string)($selectionDebug['reason'] ?? ''));
		if ($reason === '')
		{
			return '';
		}

		return 'Failed to select blocks for update: ' . $reason;
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
