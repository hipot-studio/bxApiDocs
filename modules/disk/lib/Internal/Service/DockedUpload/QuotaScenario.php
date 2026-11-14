<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Storage;

/**
 * Detects a quota signal during a docked commit and splits it into the two UX
 * scenarios agreed for v1:
 *
 *  - Mechanism 2 (per-storage size restriction) -> "box" scenario. Enforced by
 *    {@see Storage::isPossibleToUpload()} up front and by the domain write
 *    (DISK_FILE_22006) as a safety net. Applies both on cloud and on box.
 *  - Mechanism 1 (portal-wide disk space, option main/disk_space) -> "cloud"
 *    scenario on a Bitrix24 portal (an upgrade-tariff button can be offered), but
 *    the "box" scenario when there is no tariff to upgrade ({@see Bitrix24Manager::isEnabled()}
 *    is false): the option can also be set by a box admin, and box has no
 *    monetization path (AC-034/AC-064). Enforced by {@see \CDiskQuota::CheckDiskQuota()}
 *    up front and by the transport temp-save (CFile::SaveFile).
 *
 * The class only classifies, but the classification now includes the state the
 * queue must be stopped in ({@see QuotaStopInfo::getQueueStatus()}): the mechanism
 * chooses it, never the point that caught the signal. The caller
 * ({@see FileCommitService}) only applies what it is handed - it does not branch on
 * cloud/box or on mechanism 1/2 itself.
 */
class QuotaScenario
{
	public const SCENARIO_CLOUD = 'quota_cloud';
	public const SCENARIO_BOX = 'quota_box';

	/**
	 * Pre-commit quota check for a fresh upload of $size bytes into $storage.
	 *
	 * Storage size restriction is checked first, so its box classification wins
	 * when both limits would trip, matching the domain write, which raises the
	 * storage restriction before anything else.
	 *
	 * @return QuotaStopInfo|null the stop classification, or null when there is
	 *   no quota problem.
	 */
	public function detectForUpload(Storage $storage, int $size, int $userId): ?QuotaStopInfo
	{
		return $this->classify(
			storageRestrictionHit: !$storage->isPossibleToUpload($size),
			portalQuotaHit: !$this->checkPortalDiskQuota($size),
		);
	}

	/**
	 * Pure split of the two quota signals into a scenario. Storage size
	 * restriction wins when both trip, matching the domain write order.
	 */
	public function classify(bool $storageRestrictionHit, bool $portalQuotaHit): ?QuotaStopInfo
	{
		if ($storageRestrictionHit)
		{
			return $this->boxStop();
		}

		if ($portalQuotaHit)
		{
			return $this->cloudStop();
		}

		return null;
	}

	/**
	 * Classification for a storage size restriction (DISK_FILE_22006) surfaced by
	 * the domain write after the pre-check has passed (race / rounding), and for
	 * mechanism 1 where there is no tariff behind it.
	 *
	 * The queue lands in requires_action rather than stopped_by_quota: no plan
	 * upgrade moves this limit, so the way out is "free up space / ask the admin"
	 * and the state must not carry the commercial restore path (AC-032/034/064).
	 */
	public function boxStop(): QuotaStopInfo
	{
		return new QuotaStopInfo(
			scenario: self::SCENARIO_BOX,
			errorCode: self::SCENARIO_BOX,
			infoHelperCode: null,
			tariffButtonAvailable: false,
			queueStatus: QueueStatus::RequiresAction,
		);
	}

	public function cloudStop(): QuotaStopInfo
	{
		// Mechanism 1 (portal-wide main/disk_space) can also be set by an admin on a
		// box install. Without a tariff to upgrade there is no monetization path
		// (AC-034/AC-064): surface the box scenario (free up space / ask the admin),
		// never the cloud info-helper slider or tariff CTA - and, with it, the box
		// state, because the state and the scenario answer the same question.
		if (!$this->isTariffButtonAvailable())
		{
			return $this->boxStop();
		}

		return new QuotaStopInfo(
			scenario: self::SCENARIO_CLOUD,
			errorCode: self::SCENARIO_CLOUD,
			infoHelperCode: $this->getCloudInfoHelperCode(),
			tariffButtonAvailable: true,
			queueStatus: QueueStatus::StoppedByQuota,
		);
	}

	/**
	 * The single place the cloud quota info-helper code is spelled out: the queue-level
	 * resolver ({@see DisplayStatusResolver::resolveQuotaInfoHelperCode()}) reads it from
	 * here instead of repeating the literal, so the banner CTA and the transport verdict
	 * cannot drift apart. The value is the final one confirmed by product
	 * ({@see \Bitrix\Disk\Integration\MessengerCall::getInfoHelperCodeForDocuments} carries
	 * the same shape elsewhere in the module).
	 */
	public function getCloudInfoHelperCode(): string
	{
		return 'limit_disk_space';
	}

	protected function isTariffButtonAvailable(): bool
	{
		// Upgrading the tariff only makes sense on a Bitrix24 (cloud) portal.
		return Bitrix24Manager::isEnabled();
	}

	/**
	 * Portal-quota verdict for a transport failure, where the storage is unknown
	 * (belt 2, mechanism 1). The docked transport controller has no folder by
	 * construction, so only the portal-wide limit can be re-asked there; the
	 * cloud/box split stays here rather than being duplicated at the call site.
	 *
	 * @param int $size the file size, or 0 when it is unknown (a first-chunk
	 *   failure has no temp file yet) - which then asks whether any quota is left
	 *   at all.
	 */
	public function detectPortalQuotaStop(int $size): ?QuotaStopInfo
	{
		return $this->classify(
			storageRestrictionHit: false,
			portalQuotaHit: !$this->checkPortalDiskQuota($size),
		);
	}

	protected function checkPortalDiskQuota(int $size): bool
	{
		// CDiskQuota returns true when there is no portal limit (option
		// main/disk_space <= 0), i.e. a box install; a false means the portal-wide
		// quota is exhausted -> cloud scenario.
		//
		// The size MUST be passed in the array form. The scalar form is silently
		// wrong: CheckDiskQuota falls through to the `$quota - mb_strlen($params)`
		// branch, comparing the remaining quota against the LENGTH OF THE DECIMAL
		// STRING (mb_strlen(5242880) === 7), so any remainder above 7 bytes reports
		// "there is room". The array form takes the is_set($params, 'size') branch -
		// the same one CFile::validateFile() uses when it passes $arFile.
		return (new \CDiskQuota())->checkDiskQuota(['size' => $size]);
	}
}
