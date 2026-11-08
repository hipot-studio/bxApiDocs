<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Whether a submission of the form is paid for, and which pay systems of the portal it does not
 * offer.
 *
 * {@see self::$disabledPaySystemsId} is a black list: everything the portal has beside it is
 * offered, so a pay system connected later is offered by every form at once. The identifiers the
 * portal has are published by `crm.form.configuration.get`, and the list is accepted only as a
 * whole - an empty one offers every pay system of the portal.
 *
 * The list is kept whether the payment is switched on or off: reading a form and sending it back
 * has to go through on every form already carrying both.
 *
 * The list is neither filterable nor sortable: it is stored inside one serialized column, which the
 * database can neither narrow nor order a page by.
 */
final class PaymentDto extends Dto
{
	public bool $enabled;

	#[ElementsType(Type::Integer)]
	#[ElementType('int')]
	public array $disabledPaySystemsId;
}
