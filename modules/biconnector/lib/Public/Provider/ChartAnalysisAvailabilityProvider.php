<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Internal\ChartAnalysis\ChartAnalysisAvailabilityService;
use Bitrix\BIConnector\Public\Dto\ChartAnalysisAvailability;

/**
 * Tells whether the chart analysis through BitrixGPT is offered on a report to a user:
 * the report page asks it to configure the iframe, the launch command asks it again before writing.
 */
final class ChartAnalysisAvailabilityProvider
{
	public function __construct(
		private readonly ChartAnalysisAvailabilityService $service = new ChartAnalysisAvailabilityService(),
	)
	{
	}

	public function check(Model\Dashboard $dashboard, int $userId): ChartAnalysisAvailability
	{
		return $this->service->check($dashboard, $userId);
	}
}
