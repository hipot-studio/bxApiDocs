<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Tracking\Ad;

use Bitrix\Crm\Tracking\Analytics\Ad;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Refusal;
use Bitrix\Crm\V2\Internal\Integration\Seo\TrackingAdsGateway;
use Bitrix\Crm\V2\Internal\Service\Tracking\Ad\Report;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;

final class StartImport
{
	public function __construct(
		private readonly Report $report = new Report(),
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly TrackingAdsGateway $gateway = new TrackingAdsGateway(),
	)
	{
	}

	public function execute(int $userId, int $sourceId, Period $period): array
	{
		$this->access->assertCanModify($userId);
		$source = $this->report->source($sourceId);
		$this->gateway->provider((string)$source['CODE']);
		if ($source['ACTIVE'] !== 'Y')
		{
			throw Refusal::SourceArchived->exception();
		}
		$ad = new Ad($source);
		if (!$ad->isSupportExpensesReport())
		{
			throw Refusal::CapabilityUnsupported->exception();
		}
		$builder = $this->report->builder($sourceId, $period);
		$builder->run();
		if ($builder->hasErrors())
		{
			throw Refusal::RemoteError->exception();
		}

		return $this->report->status($builder);
	}
}
