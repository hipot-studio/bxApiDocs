<?php

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Main\Type\Date;

interface MetricCalculatorInterface
{
	/**
	 * @return array{
	 *     createdForms: int,
	 *     views: int,
	 *     submissions: int,
	 *     abandonedFills: int,
	 *     averageFillTimeSeconds?: int,
	 *     fullyFilled?: int,
	 *     sourceSite?: int,
	 *     sourceLanding?: int,
	 *     sourceQr?: int,
	 *     sourceEmail?: int,
	 *     sourceMessenger?: int,
	 *     sourceDirectLink?: int,
	 *     sourceWidget?: int,
	 *     sourceUnknown?: int,
	 *     deviceDesktop?: int,
	 *     deviceMobile?: int,
	 *     deviceOther?: int,
	 *     errorValidation?: int,
	 *     errorOther?: int,
	 *     typeEvent?: int,
	 *     typeStatistics?: int,
	 *     typeFeedback?: int,
	 *     typeOther?: int
	 * }
	 */
	public function calculate(Date $from, Date $to): array;
}
