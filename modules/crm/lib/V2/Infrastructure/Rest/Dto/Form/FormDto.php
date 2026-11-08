<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormDtoMapper;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;

/**
 * The external contract of a CRM form as both reads of the family publish it.
 *
 * `Filterable` and `Sortable` are closed lists that start narrow: widening either later is compatible
 * with the calls clients already make, narrowing it is not. A page of forms is narrowed by the
 * identifier, the code, the name, the enabled flag, both dates and the two fields of sections the
 * answer carries a form's own values in - the target entity of {@see CrmSettingsDto::$entityScheme}
 * and the users of {@see ResponsibleDto::$userIds}. Every other scalar published here - `system`,
 * `languageId`, `templateId`, `activeChangedById`, `activeChangedTime` - narrows no page any client
 * has asked for, so it carries neither attribute.
 *
 * The two lists are not the same list. A page is ordered by everything it is narrowed by except two
 * fields: {@see self::$code}, which no client has asked an order by, and
 * {@see ResponsibleDto::$userIds}, which is a collection - a queue of several users does not put one
 * form before another. An order is always completed by the identifier of the form, so neighbouring
 * pages cannot lose or repeat a form whose ordered value it shares with another
 * ({@see \Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormListSort}).
 *
 * The name is matched whole. The operators of the transport hold no partial match at all, so a filter
 * by a part of a name is refused rather than answered by a walk of every form of the portal.
 *
 * Neither date takes an enumeration of moments: the transport reads a moment into a typed date, and
 * its check of an enumeration takes scalars only. An interval answers a range of moments instead.
 *
 * {@see self::$updatedTime} is filtered by the value the answer shows rather than by the column
 * behind it: an empty version is answered as the date of creation, so the storage builds the
 * condition over that same standing-in value
 * ({@see \Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository}).
 *
 * A field of a section is named in a filter by the path it is read under - `crm.entityScheme`,
 * `responsible.userIds` - and the paths that may be named are the closed list of
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FormListFilterStructure}.
 */
#[Title('CRM form')]
#[MappedBy(FormDtoMapper::class)]
final class FormDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public int $id;

	#[Filterable]
	public ?string $code;

	#[Filterable]
	#[Sortable]
	public string $name;

	#[Filterable]
	#[Sortable]
	public bool $active;

	public bool $system;

	public ?string $languageId;

	public ?string $templateId;

	#[Filterable]
	#[Sortable]
	public DateTime $createdTime;

	#[Filterable]
	#[Sortable]
	public DateTime $updatedTime;

	public ?int $activeChangedById;

	public ?DateTime $activeChangedTime;

	public ?AppearanceDto $appearance;

	public ?CrmSettingsDto $crm;

	/** @var DtoCollection<DependencyGroupDto>|null */
	#[ElementType(DependencyGroupDto::class)]
	public ?DtoCollection $dependencies;

	/** @var DtoCollection<PresetFieldDto>|null */
	#[ElementType(PresetFieldDto::class)]
	public ?DtoCollection $presetFields;

	public ?ResponsibleDto $responsible;

	public ?AgreementsDto $agreements;

	public ?SubmissionDto $submission;

	public ?PaymentDto $payment;

	public ?CallbackDto $callback;

	public ?BookingDto $booking;

	public ?AnalyticsDto $analytics;

	public ?SpamProtectionDto $spamProtection;

	public ?IntegrationsDto $integrations;

	protected function getExtraFields(): array
	{
		return [
			new DtoField(
				propertyName: 'fields',
				propertyType: DtoCollection::class,
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				multiple: true,
				nullable: true,
				elementType: FieldDto::class,
			),
		];
	}
}
