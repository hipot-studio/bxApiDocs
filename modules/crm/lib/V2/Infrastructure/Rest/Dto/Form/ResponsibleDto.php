<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Who answers a submission of the form: the queue of the responsible, and whether their working hours
 * are taken into account when the next one is picked.
 *
 * The users are filterable, and a filter names them by the path they are read under -
 * `responsible.userIds`, the closed list of {@see FormDto} - because "the forms this employee answers"
 * is a regular question about a page of forms. The value of such a condition is a user rather than the
 * whole list a form carries: one identifier, or their enumeration, and only a positive one, since the
 * answer builds the list out of two sources and drops every identifier that is not positive
 * ({@see \Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository}).
 *
 * {@see self::$checkWorkTime} carries no attribute: it decides how the queue is walked and narrows no
 * page of the list.
 */
final class ResponsibleDto extends Dto
{
	#[Filterable]
	#[ElementsType(Type::Integer)]
	#[ElementType('int')]
	public array $userIds;

	public bool $checkWorkTime;
}
