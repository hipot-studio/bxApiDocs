<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\DictionaryValueDtoMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a value of a dictionary of CRM: `crm.dictionary.{dealType|contactType|
 * honorific|companyType|employees|industry|source}.*`. One class answers all seven resources - they are
 * the same four fields with the same meanings, and which dictionary a value belongs to is said by the
 * name of the method rather than by a field of the request.
 *
 * None of the types takes `null`, unlike the nested objects the reading of a CRM item answers with
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\DealTypeDto} and its six
 * siblings, where `name`, `sort` and `isSystem` do). Storage keeps none of the three null, so a nullable
 * type here would promise a client more than the contract allows - and refusing an explicit `null` costs
 * nothing on top: the type of the field does it.
 *
 * No field has a default value either, and that is what keeps a partial update partial: a field left out
 * of a request stays uninitialised, which the framework leaves alone
 * ({@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper::validate()}), while a default would turn "not sent" into
 * "sent with this value".
 *
 * What the rules of a value are is none of this class's business: an empty name and a name longer than
 * storage keeps are answered by the domain
 * ({@see \Bitrix\Crm\V2\Internal\Service\Dictionary\DictionaryValueService}), and a second place for the
 * same rule is what the regulation forbids.
 */
#[Title('CRM dictionary entry')]
#[MappedBy(DictionaryValueDtoMapper::class)]
final class DictionaryValueDto extends Dto
{
	/**
	 * The identifier a client addresses a value by, `PARTNER`, `EMPLOYEES_1`. It is written on an add
	 * alone - an empty one there asks the server to generate it - and never changed afterwards.
	 *
	 * `#[Required(['add'])]` is deliberately absent: a field required in any group is treated as required
	 * always and skips the editable check altogether
	 * ({@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper::isFieldRequired()}). Without it the editable check
	 * does its work, and `fields.id` of an update is refused by the transport itself - the way
	 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category\Category} has to work around by
	 * hand for the category of a stage.
	 */
	#[Editable(['add'])]
	#[Filterable]
	#[Sortable]
	public string $id;

	#[Editable]
	#[Required(['add'])]
	#[Filterable]
	#[Sortable]
	public string $name;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public int $sort;

	/**
	 * Whether a value ships with the product. Read-only in every group, so writing it is refused rather
	 * than dropped in silence, and not sortable: an order by the mark says nothing a client can use.
	 */
	#[Filterable]
	public bool $isSystem;
}
