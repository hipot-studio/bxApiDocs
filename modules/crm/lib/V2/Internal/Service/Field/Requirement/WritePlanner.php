<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Attribute\FieldAttributePhaseGroupType;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Error;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Exception;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\SetInput;

/**
 * @internal
 */
final class WritePlanner
{
	/**
	 * @param list<array{id: string, sort: int, semantics: string, internalId: int}> $stages
	 */
	public function plan(
		SetInput $input,
		Context $context,
		array $stages,
		bool $available,
	): WritePlan
	{
		if (!$input->hasRequiredStageIds())
		{
			return new WritePlan(LegacyRequirementRule::all(), null);
		}

		$requiredStageIds = $input->getRequiredStageIds();
		if ($requiredStageIds === null)
		{
			throw new Exception(Error::REQUIRED_STAGE_IDS_NULL);
		}

		$requiredStageIds = $this->normalizeStageIds($requiredStageIds);
		if (!$context->supportsStages)
		{
			throw new Exception(Error::STAGES_NOT_SUPPORTED);
		}
		if (!$available)
		{
			throw new Exception(Error::STAGE_REQUIREMENT_NOT_AVAILABLE);
		}

		$stagesById = [];
		foreach ($stages as $stage)
		{
			$stagesById[$stage['id']] = $stage;
		}

		$selected = [];
		foreach ($requiredStageIds as $stageId)
		{
			$stage = $stagesById[$stageId] ?? null;
			if ($stage === null)
			{
				throw new Exception(Error::STAGE_NOT_FOUND);
			}

			$selected[$stageId] = $stage;
		}

		$this->assertEqualSortBucketsAreComplete($selected, $stages);
		usort(
			$selected,
			static fn (array $left, array $right): int => ($left['sort'] <=> $right['sort'])
				?: strcmp($left['id'], $right['id']),
		);

		$ranges = [];
		foreach ($selected as $stage)
		{
			$ranges[] = new LegacyRequirementRange(
				$this->phaseGroupTypeId($stage),
				$stage['id'],
				$stage['id'],
			);
		}

		return new WritePlan(
			LegacyRequirementRule::ranges($ranges),
			array_values(array_column($selected, 'id')),
		);
	}

	/**
	 * @param array<array-key, mixed> $stageIds
	 * @return non-empty-list<string>
	 */
	private function normalizeStageIds(array $stageIds): array
	{
		$unique = [];
		foreach ($stageIds as $stageId)
		{
			if (!is_string($stageId) || $stageId === '')
			{
				throw new Exception(Error::REQUIRED_STAGE_ID_INVALID);
			}

			$unique[$stageId] = $stageId;
		}

		if ($unique === [])
		{
			throw new Exception(Error::REQUIRED_STAGE_ID_INVALID);
		}

		return array_values($unique);
	}

	/**
	 * @param array<string, array{id: string, sort: int, semantics: string, internalId: int}> $selected
	 * @param list<array{id: string, sort: int, semantics: string, internalId: int}> $stages
	 */
	private function assertEqualSortBucketsAreComplete(array $selected, array $stages): void
	{
		$selectedBuckets = [];
		foreach ($selected as $stage)
		{
			$selectedBuckets[$this->bucketKey($stage)][] = $stage['id'];
		}

		$allBuckets = [];
		foreach ($stages as $stage)
		{
			$allBuckets[$this->bucketKey($stage)][] = $stage['id'];
		}

		foreach ($selectedBuckets as $bucketKey => $stageIds)
		{
			if (count($stageIds) !== count($allBuckets[$bucketKey] ?? []))
			{
				throw new Exception(Error::STAGE_SELECTION_NOT_REPRESENTABLE);
			}
		}
	}

	/**
	 * @param array{id: string, sort: int, semantics: string, internalId: int} $stage
	 */
	private function bucketKey(array $stage): int
	{
		return $stage['sort'];
	}

	/**
	 * @param array{id: string, sort: int, semantics: string, internalId: int} $stage
	 */
	private function phaseGroupTypeId(array $stage): int
	{
		return PhaseSemantics::isLost($stage['semantics'])
			? FieldAttributePhaseGroupType::JUNK
			: FieldAttributePhaseGroupType::PIPELINE;
	}
}
