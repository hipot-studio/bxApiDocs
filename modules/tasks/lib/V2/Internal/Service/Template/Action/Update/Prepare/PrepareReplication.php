<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\Util\Type;

class PrepareReplication implements PrepareFieldInterface
{
	/** Internal: OFFSET restored from DEADLINE_AFTER; not a DB column. */
	public const DEADLINE_OFFSET_HEALED = '__DEADLINE_OFFSET_HEALED';

	public function __invoke(array $fields, array $fullTemplateData): array
	{
		$isReplicateParamsProvided = isset($fields['REPLICATE_PARAMS']);
		$isDeadlineAfterProvided = isset($fields['DEADLINE_AFTER']);
		$hasCurrentReplicateParams = !empty($fullTemplateData['REPLICATE_PARAMS']);
		$hasReplicationContext = $this->hasReplicationContext($fields, $hasCurrentReplicateParams);

		$dbReplicateParams = $this->extractReplicateParams($fullTemplateData['REPLICATE_PARAMS'] ?? null);
		$dbDeadlineAfter = (int)($fullTemplateData['DEADLINE_AFTER'] ?? 0);
		$dbDeadlineOffset = array_key_exists('DEADLINE_OFFSET', $dbReplicateParams)
			? (int)$dbReplicateParams['DEADLINE_OFFSET']
			: null;

		if (isset($fields['TPARAM_REPLICATION_COUNT']))
		{
			$fields['TPARAM_REPLICATION_COUNT'] = (int)$fields['TPARAM_REPLICATION_COUNT'];
		}

		if (!isset($fields['REPLICATE']) && !$isReplicateParamsProvided && !$isDeadlineAfterProvided)
		{
			return $fields;
		}

		if (
			!isset($fields['REPLICATE'])
			&& !$isReplicateParamsProvided
			&& $isDeadlineAfterProvided
			&& !$hasReplicationContext
		)
		{
			return $fields;
		}

		if (
			(isset($fields['REPLICATE']) || $isDeadlineAfterProvided)
			&& !$isReplicateParamsProvided
			&& $hasCurrentReplicateParams
		)
		{
			$fields['REPLICATE_PARAMS'] = $fullTemplateData['REPLICATE_PARAMS'];
		}

		if (
			is_string($fields['REPLICATE_PARAMS'] ?? null)
			&& !empty($fields['REPLICATE_PARAMS'])
		)
		{
			$fields['REPLICATE_PARAMS'] = Type::unSerializeArray($fields['REPLICATE_PARAMS']);
		}

		if (!array_key_exists('REPLICATE_PARAMS', $fields))
		{
			return $fields;
		}

		if (empty($fields['REPLICATE_PARAMS']))
		{
			$fields['REPLICATE_PARAMS'] = [];
		}

		$shouldSyncFromDeadlineAfter = $this->shouldSyncFromDeadlineAfter(
			$isDeadlineAfterProvided,
			$isReplicateParamsProvided,
			$hasReplicationContext,
		);

		if ($shouldSyncFromDeadlineAfter)
		{
			$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] = (int)$fields['DEADLINE_AFTER'];
		}

		if (
			$hasCurrentReplicateParams
			&& !array_key_exists('DEADLINE_OFFSET', $fields['REPLICATE_PARAMS'])
		)
		{
			$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] = (int)(
				$isDeadlineAfterProvided
					? $fields['DEADLINE_AFTER']
					: $dbDeadlineAfter
			);
		}

		$incomingDeadlineAfter = $isDeadlineAfterProvided ? (int)$fields['DEADLINE_AFTER'] : null;
		$effectiveOffsetBeforeHeal = array_key_exists('DEADLINE_OFFSET', $fields['REPLICATE_PARAMS'])
			? (int)$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET']
			: null;

		$isCanonicalClear = $incomingDeadlineAfter === 0;
		$isCanonicalSet = $incomingDeadlineAfter !== null && $incomingDeadlineAfter > 0;
		$isLegacyOffsetClear = !$isCanonicalSet
			&& $effectiveOffsetBeforeHeal === 0
			&& $dbDeadlineOffset !== null
			&& $dbDeadlineOffset > 0;

		// Heal only when no canonical/legacy clear|set wins — marker reflects the final decision.
		$needsDeadlineOffsetHeal = !$isCanonicalClear
			&& !$isCanonicalSet
			&& !$isLegacyOffsetClear
			&& ($effectiveOffsetBeforeHeal === null || $effectiveOffsetBeforeHeal === 0)
			&& $dbDeadlineAfter > 0;

		if ($isCanonicalClear || $isLegacyOffsetClear)
		{
			$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] = 0;
		}
		elseif ($isCanonicalSet)
		{
			$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] = $incomingDeadlineAfter;
		}
		elseif ($needsDeadlineOffsetHeal)
		{
			$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] = $dbDeadlineAfter;
			$fields[self::DEADLINE_OFFSET_HEALED] = true;
		}

		$fields['REPLICATE_PARAMS'] = Options::validate($fields['REPLICATE_PARAMS']);

		if ($isCanonicalClear || $isLegacyOffsetClear)
		{
			$fields['DEADLINE_AFTER'] = 0;
			$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] = 0;

			return $fields;
		}

		if ($isCanonicalSet || $shouldSyncFromDeadlineAfter)
		{
			$fields['DEADLINE_AFTER'] = (int)$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'];

			return $fields;
		}

		$wasDeadlineOffsetHealed = !empty($fields[self::DEADLINE_OFFSET_HEALED]);
		$effectiveOffset = (int)($fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] ?? 0);
		$shouldSyncAfterFromOffset = $this->shouldSyncAfterFromPositiveOffset(
			$isDeadlineAfterProvided,
			$hasReplicationContext,
			$fields,
			$isReplicateParamsProvided,
			$effectiveOffset,
			$dbDeadlineOffset,
			$dbDeadlineAfter,
			$wasDeadlineOffsetHealed,
		);

		if ($shouldSyncAfterFromOffset)
		{
			$fields['DEADLINE_AFTER'] = $effectiveOffset;
		}

		return $fields;
	}

	private function extractReplicateParams(mixed $raw): array
	{
		if (is_array($raw))
		{
			return $raw;
		}

		if (is_string($raw) && $raw !== '')
		{
			$parsed = Type::unSerializeArray($raw);

			return is_array($parsed) ? $parsed : [];
		}

		return [];
	}

	private function hasReplicationContext(array $fields, bool $hasCurrentReplicateParams): bool
	{
		return isset($fields['REPLICATE_PARAMS'])
			|| $hasCurrentReplicateParams
			|| (($fields['REPLICATE'] ?? null) === true)
		;
	}

	private function shouldSyncFromDeadlineAfter(
		bool $isDeadlineAfterProvided,
		bool $isReplicateParamsProvided,
		bool $hasReplicationContext,
	): bool
	{
		return $isDeadlineAfterProvided
			&& !$isReplicateParamsProvided
			&& $hasReplicationContext
		;
	}

	/**
	 * Pull DEADLINE_AFTER from a positive OFFSET when the caller changed OFFSET (or pair is reverse-skewed)
	 * and AFTER was not sent. Skip when OFFSET was only healed from AFTER.
	 */
	private function shouldSyncAfterFromPositiveOffset(
		bool $isDeadlineAfterProvided,
		bool $hasReplicationContext,
		array $fields,
		bool $isReplicateParamsProvided,
		int $effectiveOffset,
		?int $dbDeadlineOffset,
		int $dbDeadlineAfter,
		bool $wasDeadlineOffsetHealed,
	): bool
	{
		if (
			$wasDeadlineOffsetHealed
			|| $isDeadlineAfterProvided
			|| !$hasReplicationContext
			|| $effectiveOffset <= 0
		)
		{
			return false;
		}

		if (!(isset($fields['REPLICATE']) || $isReplicateParamsProvided))
		{
			return false;
		}

		// Reverse skew: sheet shows OFFSET, but DEADLINE_AFTER was wiped — restore AFTER.
		if ($dbDeadlineAfter === 0)
		{
			return true;
		}

		return $dbDeadlineOffset === null || $dbDeadlineOffset !== $effectiveOffset;
	}
}
