<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Tracking\Ad;

use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Crm\V2\Internal\Service\Tracking\Ad\AnalyticsService;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;

final class SourceAnalytics
{
	public function __construct(
		private readonly ?AnalyticsService $service = null,
		private readonly ?TrackingAccess $access = null,
	)
	{
	}

	public function list(int $userId, array $sourceIds, Period $period): array
	{
		($this->access ?? new TrackingAccess())->assertCanRead($userId);

		return ($this->service ?? new AnalyticsService())->list($sourceIds, $period);
	}
}
