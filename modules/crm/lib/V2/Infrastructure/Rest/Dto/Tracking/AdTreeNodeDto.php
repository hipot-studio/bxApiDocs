<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('CRM tracking advertising report row')]
final class AdTreeNodeDto extends Dto
{
	public const OUTPUT_FIELDS = [
		'id',
		'code',
		'enabled',
		'title',
		'level',
		'nextLevel',
		'prevLevel',
		'impressions',
		'actions',
		'outcome',
		'currencyId',
		'leads',
		'deals',
		'successDeals',
		'cost',
		'income',
		'roi',
		'roiScale',
		'ctr',
		'cpc',
	];

	public int $id;

	public string $code;

	public bool $enabled;

	public string $title;

	public int $level;

	public ?int $nextLevel;

	public ?int $prevLevel;

	public int $impressions;

	public int $actions;

	public int $outcome;

	public string $currencyId;

	public int $leads;

	public int $deals;

	public int $successDeals;

	public float $cost;

	public float $income;

	public float $roi;

	public int $roiScale;

	public float $ctr;

	public float $cpc;
}
