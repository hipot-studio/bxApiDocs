<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Storage;

/**
 * The module-level answer to "why can this upload not be stored", splitting a shortage of space into the
 * two scenarios a client has to render differently: the per-storage size restriction and the portal-wide
 * disk space limit. Every uploader that needs the split classifies here, so that the cloud info-helper
 * code and the cloud/box verdict stay spelled out in one place.
 */
class UploadQuotaScenario
{
	public const SCENARIO_CLOUD = 'quota_cloud';
	public const SCENARIO_BOX = 'quota_box';

	/**
	 * Pre-check before $size bytes are stored into $storage.
	 *
	 * @return UploadQuotaStopInfo|null the stop classification, or null when there is no shortage.
	 */
	public function detectForUpload(Storage $storage, int $size): ?UploadQuotaStopInfo
	{
		return $this->classify(
			storageRestrictionHit: !$storage->isPossibleToUpload($size),
			portalQuotaHit: !$this->checkPortalDiskQuota($size),
		);
	}

	/**
	 * Pure split of the two signals into a scenario. The storage size restriction wins when both trip:
	 * it is the limit the domain write raises first, and no tariff moves it.
	 */
	public function classify(bool $storageRestrictionHit, bool $portalQuotaHit): ?UploadQuotaStopInfo
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
	 * The single place the cloud quota info-helper code is spelled out, so that the banner CTA and the
	 * upload verdict cannot drift apart.
	 */
	public function getCloudInfoHelperCode(): string
	{
		return 'limit_disk_space';
	}

	protected function isTariffButtonAvailable(): bool
	{
		// Upgrading the tariff only makes sense on a Bitrix24 portal.
		return Bitrix24Manager::isEnabled();
	}

	protected function checkPortalDiskQuota(int $size): bool
	{
		// The size MUST be passed in the array form. The scalar form is silently wrong: with the quota
		// already exhausted CheckDiskQuota() falls through to its `$quota - mb_strlen($params)` branch
		// and weighs the remainder against the LENGTH OF THE DECIMAL STRING (mb_strlen('5242880') === 7),
		// so any remainder above 7 bytes still reports "there is room". The array form takes the
		// is_set($params, 'size') branch - the one CFile::validateFile() uses when it passes $arFile.
		return (bool)$this->createPortalQuota()->checkDiskQuota(['size' => $size]);
	}

	/**
	 * Seam over the legacy quota class, which has no interface to substitute and reads portal options on
	 * its own: it lets the array form above be verified without a portal behind the test.
	 */
	protected function createPortalQuota(): \CDiskQuota
	{
		return new \CDiskQuota();
	}

	private function boxStop(): UploadQuotaStopInfo
	{
		return new UploadQuotaStopInfo(
			scenario: self::SCENARIO_BOX,
			errorCode: self::SCENARIO_BOX,
			infoHelperCode: null,
			tariffButtonAvailable: false,
		);
	}

	private function cloudStop(): UploadQuotaStopInfo
	{
		// The portal-wide limit can be set by a box admin too, and there a plan upgrade is not on offer:
		// without a tariff behind it the cloud scenario has nothing to propose and degrades to the box
		// one - free up space or ask the admin.
		if (!$this->isTariffButtonAvailable())
		{
			return $this->boxStop();
		}

		return new UploadQuotaStopInfo(
			scenario: self::SCENARIO_CLOUD,
			errorCode: self::SCENARIO_CLOUD,
			infoHelperCode: $this->getCloudInfoHelperCode(),
			tariffButtonAvailable: true,
		);
	}
}
