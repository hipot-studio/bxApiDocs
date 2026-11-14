<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use JsonSerializable;

final class AuditSettingsState implements JsonSerializable
{
	public const BLOCKED_REASON_LICENSE_UNAVAILABLE = 'license_unavailable';

	public function __construct(
		public readonly bool $enabled,
		public readonly string $retentionPeriod,
		public readonly bool $wasEverEnabled,
		public readonly bool $canCollect,
		public readonly bool $canView,
		public readonly bool $canEditSettings,
		public readonly bool $licenseAvailable,
		public readonly ?string $blockedReason = null,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'enabled' => $this->enabled,
			'retentionPeriod' => $this->retentionPeriod,
			'wasEverEnabled' => $this->wasEverEnabled,
			'canCollect' => $this->canCollect,
			'canView' => $this->canView,
			'canEditSettings' => $this->canEditSettings,
			'licenseAvailable' => $this->licenseAvailable,
			'blockedReason' => $this->blockedReason,
		];
	}
}
