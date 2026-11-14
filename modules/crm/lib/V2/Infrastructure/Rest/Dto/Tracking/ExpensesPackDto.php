<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a pack of manual expenses of a source of the end-to-end analytics: the totals of a
 * period as they were entered.
 *
 * The days of the period are dates without a time and a time zone, `YYYY-MM-DD` in both directions; the moment
 * the pack was added is a date and time of REST V3. The source the packs are listed for is a required filter by
 * {@see self::$sourceId}.
 */
#[Title('CRM tracking expenses')]
final class ExpensesPackDto extends Dto
{
	public const OUTPUT_FIELDS = [
		'id',
		'sourceId',
		'dateFrom',
		'dateTo',
		'amount',
		'currencyId',
		'actions',
		'comment',
		'createdTime',
	];

	public int $id;

	#[Filterable]
	#[Editable(['add'])]
	#[Required(['add'])]
	#[Description('Identifier of the source, active or archived. The list of the expenses requires a filter by it.')]
	public int $sourceId;

	#[Editable(['add'])]
	#[Required(['add'])]
	#[Description('First day of the period, YYYY-MM-DD.')]
	public Date $dateFrom;

	#[Editable(['add'])]
	#[Required(['add'])]
	#[Description('Last day of the period, YYYY-MM-DD, not before the first one. A period is at most 365 days.')]
	public Date $dateTo;

	#[Editable(['add'])]
	#[Required(['add'])]
	#[Description(
		'Amount spent in the period: greater than zero when rounded to cents (to the precision of the currency'
		. ' when it is coarser) and less than 10^12.'
	)]
	public float $amount;

	#[Editable(['add'])]
	#[Required(['add'])]
	#[Description('Currency of the amount, a code of a currency of the portal such as USD.')]
	public string $currencyId;

	#[Editable(['add'])]
	#[Description('Number of target actions in the period, from 0 to 2147483647. Zero when omitted.')]
	public int $actions;

	#[Editable(['add'])]
	#[Description('Comment to the expenses, at most 255 characters.')]
	public ?string $comment;

	#[Description('Date and time the expenses were added.')]
	public ?DateTime $createdTime;
}
