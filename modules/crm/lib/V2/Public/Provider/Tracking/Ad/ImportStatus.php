<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Tracking\Ad;

use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Crm\V2\Internal\Service\Tracking\Ad\Report;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;

final class ImportStatus
{
	public function __construct(
		private readonly Report $report = new Report(),
		private readonly TrackingAccess $access = new TrackingAccess(),
	)
	{
	}

	public function get(int $userId, int $sourceId, Period $period): array
	{
		$this->access->assertCanRead($userId);

		return $this->report->status($this->report->builder($sourceId, $period));
	}
}
