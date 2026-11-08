<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\DataViewRepository;

use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewFreshness;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewSummary;
use Bitrix\Main\Type\DateTime;

interface DataViewRepositoryInterface
{
	public function getByStorageTypeId(int $storageTypeId): ?DataView;

	/**
	 * Reads only the fields the read path needs to judge freshness, without loading
	 * and decoding the definition JSON.
	 */
	public function getFreshness(int $storageTypeId): ?DataViewFreshness;

	/**
	 * Reads only the fields the views list needs, without loading the TEXT columns
	 * (DEFINITION, DELETION_MARKS) or decoding their JSON.
	 *
	 * @return DataViewSummary[] ordered by id ascending
	 */
	public function getSummariesByOwner(int $templateId, string $activityName): array;

	public function getStorageTypeIds(): array;

	/**
	 * Reads only the owner column of every view, without loading the TEXT columns
	 * (DEFINITION, DELETION_MARKS) or decoding their JSON.
	 *
	 * @return array<int, int|null> owner template id by storage type id, ordered by id ascending
	 */
	public function getOwnerTemplateIds(): array;

	public function save(DataView $dataView): DataView;

	public function deleteByStorageTypeId(int $storageTypeId): void;

	public function markActual(DataView $dataView, DateTime $materializedAt, int $materializedBy): void;

	public function markBroken(DataView $dataView, string $errorText): void;

	public function appendDeletionMarks(DataView $dataView, array $identities): void;

	public function acquireCatalogLock(): bool;

	public function releaseCatalogLock(): void;

	public function acquireViewLock(int $storageTypeId): bool;

	public function releaseViewLock(int $storageTypeId): void;
}
