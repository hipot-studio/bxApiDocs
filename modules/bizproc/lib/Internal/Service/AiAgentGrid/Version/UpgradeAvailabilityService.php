<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Type\DateTime;

/**
 * Computes upgrade-availability and customization state (DTO-01) for launched
 * AI-agent copies.
 *
 * For a launched copy linked to a system template via ORIGIN_SYSTEM_CODE:
 *  - hasNewVersion: reference (system template) revision != copy installed
 *    version (ORIGIN_SYSTEM_VERSION);
 *  - isCustomized: copy current-logic revision != installed version;
 *  - isComparable: every revision the two flags above stand on was available, so a pair of
 *    false flags means "compared, no difference" and never "nothing was compared".
 *
 * Performance: a timestamp prefilter avoids the full logic hash on the hot path.
 *  - Copy customization is skipped unless the copy is flagged IS_MODIFIED, since
 *    a copy whose logic was never edited cannot have diverged.
 *  - The reference revision is computed once per distinct system code and cached
 *    for the lifetime of the service instance (one grid render).
 */
class UpgradeAvailabilityService
{
	/** @var array<string, string|null> system code => reference revision */
	private array $referenceRevisionCache = [];

	/** @var array<string, array{id: int, modified: ?DateTime}|null> system code => reference row */
	private array $referenceRowCache = [];

	/** @var array<int, array{code: ?string, version: ?string}> template id => batch-preloaded origin settings */
	private array $originSettingsCache = [];

	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository = new AiAgentRepository(),
		private readonly TemplateRevisionService $templateRevisionService = new TemplateRevisionService(),
	) {}

	/**
	 * Batches only the copies' ORIGIN_SYSTEM_CODE/VERSION: loads them for a page of grid rows in a
	 * single query and seeds the per-template cache, so the subsequent per-row
	 * getAvailabilityForRow() reads those two settings from memory instead of issuing per-copy
	 * settings reads (NFR P95 <= 500 ms). It does not batch the rest: getAvailabilityForRow() still
	 * reads the reference row and revision from the database (getReferenceRow/getReferenceRevision,
	 * each computed once per distinct system code and cached for the service-instance lifetime) and
	 * still hashes the current logic of every modified copy (isCopyCustomized), per row.
	 *
	 * Only launched copies (SYSTEM_CODE IS NULL) can be linked to a reference; system-template
	 * rows are skipped, since getAvailabilityForRow() early-returns for them without reading
	 * settings. Every requested id is seeded (a copy without origin settings as an explicit null
	 * entry) so it does not fall back to a per-row query.
	 *
	 * @param list<array> $templateRows Grid rows; each expected to carry ID and SYSTEM_CODE.
	 */
	public function preloadOriginSettings(array $templateRows): void
	{
		$copyIds = [];
		foreach ($templateRows as $templateRow)
		{
			$templateId = (int)($templateRow['ID'] ?? 0);
			if ($templateId > 0 && empty($templateRow['SYSTEM_CODE']))
			{
				$copyIds[] = $templateId;
			}
		}

		if ($copyIds === [])
		{
			return;
		}

		$settings = $this->aiAgentRepository->getOriginSettings($copyIds);
		foreach ($copyIds as $copyId)
		{
			$this->originSettingsCache[$copyId] = [
				'code' => $settings[$copyId]['code'] ?? null,
				'version' => $settings[$copyId]['version'] ?? null,
			];
		}
	}

	/**
	 * @param array $templateRow A launched-copy grid row. Expected keys:
	 *   ID, SYSTEM_CODE, IS_MODIFIED (optional), MODIFIED (optional).
	 */
	public function getAvailabilityForRow(array $templateRow): UpgradeAvailabilityDto
	{
		$templateId = (int)($templateRow['ID'] ?? 0);

		// Only launched copies (SYSTEM_CODE IS NULL) can be linked to a reference.
		if ($templateId <= 0 || !empty($templateRow['SYSTEM_CODE']))
		{
			return new UpgradeAvailabilityDto();
		}

		$originSystemCode = $this->getOriginSystemCode($templateId);
		if ($originSystemCode === null)
		{
			return new UpgradeAvailabilityDto();
		}

		$referenceRow = $this->getReferenceRow($originSystemCode);
		if ($referenceRow === null)
		{
			return new UpgradeAvailabilityDto(hasSystemOrigin: true);
		}

		$installedVersion = $this->getOriginSystemVersion($templateId);
		$referenceRevision = $this->getReferenceRevision($originSystemCode, $referenceRow['id']);
		$isCustomized = $this->isCopyCustomized($templateRow, $templateId, $installedVersion);

		if ($referenceRevision === null || $isCustomized === null)
		{
			return new UpgradeAvailabilityDto(
				currentVersionLabel: $this->makeVersionLabel($installedVersion),
				hasSystemOrigin: true,
			);
		}

		$hasNewVersion = $referenceRevision !== $installedVersion;

		return new UpgradeAvailabilityDto(
			hasNewVersion: $hasNewVersion,
			isCustomized: $isCustomized,
			currentVersionLabel: $this->makeVersionLabel($installedVersion),
			newVersionLabel: $hasNewVersion
				? $this->makeVersionLabel($referenceRevision, $referenceRow['modified'])
				: null,
			isComparable: true,
			hasSystemOrigin: true,
		);
	}

	/**
	 * Whether the copy logic diverged from its installed version; null when that question has no
	 * answer - there is no installed version to compare with, or the copy revision does not compute.
	 *
	 * Fast prefilter: a copy that was never edited (IS_MODIFIED != 'Y') cannot
	 * be customized, so the full logic hash is skipped. Only a modified copy is
	 * hashed and compared against the installed version.
	 */
	private function isCopyCustomized(array $templateRow, int $templateId, ?string $installedVersion): ?bool
	{
		if ($installedVersion === null)
		{
			return null;
		}

		if (array_key_exists('IS_MODIFIED', $templateRow) && (string)$templateRow['IS_MODIFIED'] !== 'Y')
		{
			return false;
		}

		$currentRevision = $this->templateRevisionService->getTemplateRevision($templateId);
		if ($currentRevision === null)
		{
			return null;
		}

		return $currentRevision !== $installedVersion;
	}

	/**
	 * ORIGIN_SYSTEM_CODE of a copy, served from the batch-preloaded cache when available (grid
	 * page) and falling back to a single-row repository read otherwise (single-row callers/tests).
	 */
	private function getOriginSystemCode(int $templateId): ?string
	{
		if (array_key_exists($templateId, $this->originSettingsCache))
		{
			return $this->originSettingsCache[$templateId]['code'];
		}

		return $this->aiAgentRepository->getOriginSystemCode($templateId);
	}

	/**
	 * ORIGIN_SYSTEM_VERSION of a copy, served from the batch-preloaded cache when available and
	 * falling back to a single-row repository read otherwise (mirror of getOriginSystemCode()).
	 */
	private function getOriginSystemVersion(int $templateId): ?string
	{
		if (array_key_exists($templateId, $this->originSettingsCache))
		{
			return $this->originSettingsCache[$templateId]['version'];
		}

		return $this->aiAgentRepository->getOriginSystemVersion($templateId);
	}

	/**
	 * @return array{id: int, modified: ?DateTime}|null
	 */
	private function getReferenceRow(string $systemCode): ?array
	{
		if (array_key_exists($systemCode, $this->referenceRowCache))
		{
			return $this->referenceRowCache[$systemCode];
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['ID', 'MODIFIED'])
			->where('SYSTEM_CODE', $systemCode)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return $this->referenceRowCache[$systemCode] = null;
		}

		$modified = $row['MODIFIED'] ?? null;

		return $this->referenceRowCache[$systemCode] = [
			'id' => (int)$row['ID'],
			'modified' => $modified instanceof DateTime ? $modified : null,
		];
	}

	private function getReferenceRevision(string $systemCode, int $referenceId): ?string
	{
		if (array_key_exists($systemCode, $this->referenceRevisionCache))
		{
			return $this->referenceRevisionCache[$systemCode];
		}

		return $this->referenceRevisionCache[$systemCode] =
			$this->templateRevisionService->getTemplateRevision($referenceId)
		;
	}

	/**
	 * Human-readable version label for the dialog: reference version prefers a
	 * date; installed version falls back to a short revision. Empty when unknown.
	 */
	private function makeVersionLabel(?string $revision, ?DateTime $modified = null): string
	{
		if ($modified !== null)
		{
			return \FormatDate('d.m.Y', $modified->getTimestamp());
		}

		if ($revision === null || $revision === '')
		{
			return '';
		}

		return substr($revision, 0, 8);
	}
}
