<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('CRM tracking source analytics')]
final class SourceAnalyticsDto extends Dto
{
	public const OUTPUT_FIELDS = [
		'sourceId',
		'dateFrom',
		'dateTo',
		'currencyId',
		'stages',
		'cost',
		'income',
		'roi',
	];

	#[Description('Identifier of the active tracking source.')]
	public int $sourceId;

	#[Description('First day of the inclusive period, YYYY-MM-DD in the portal time zone.')]
	public string $dateFrom;

	#[Description('Last day of the inclusive period, YYYY-MM-DD in the portal time zone.')]
	public string $dateTo;

	#[Description('CRM accounting currency used by the tracking dashboard.')]
	public string $currencyId;

	#[Description('Dashboard stages in UI order; each has code, quantity, sum and views.')]
	public array $stages;

	#[Description('Action-stage cost, including live advertising and manual expenses.')]
	public float $cost;

	#[Description('Final-stage income in CRM accounting currency.')]
	public float $income;

	#[Description('Dashboard return on investment in percent, rounded to two decimal places.')]
	public float $roi;
}
