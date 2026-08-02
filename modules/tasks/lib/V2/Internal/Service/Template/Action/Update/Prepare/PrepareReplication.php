<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\Util\Type;

class PrepareReplication implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		$isReplicateParamsProvided = isset($fields['REPLICATE_PARAMS']);
		$isDeadlineAfterProvided = isset($fields['DEADLINE_AFTER']);
		$hasCurrentReplicateParams = !empty($fullTemplateData['REPLICATE_PARAMS']);
		$hasReplicationContext = $this->hasReplicationContext($fields, $hasCurrentReplicateParams);

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
					: ($fullTemplateData['DEADLINE_AFTER'] ?? 0)
			);
		}

		$fields['REPLICATE_PARAMS'] = Options::validate($fields['REPLICATE_PARAMS']);

		if (
			$this->shouldSyncFromReplicateParams(
				$shouldSyncFromDeadlineAfter,
				$hasReplicationContext,
				$fields,
				$isReplicateParamsProvided,
			)
		)
		{
			$fields['DEADLINE_AFTER'] = (int)($fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] ?? 0);
		}

		return $fields;
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

	private function shouldSyncFromReplicateParams(
		bool $shouldSyncFromDeadlineAfter,
		bool $hasReplicationContext,
		array $fields,
		bool $isReplicateParamsProvided,
	): bool
	{
		return !$shouldSyncFromDeadlineAfter
			&& $hasReplicationContext
			&& (isset($fields['REPLICATE']) || $isReplicateParamsProvided)
		;
	}
}
