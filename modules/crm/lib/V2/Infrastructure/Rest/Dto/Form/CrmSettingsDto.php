<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The CRM settings of a form: which entities a submission creates, in which funnel of them, and how
 * a repeated submission is matched against what the CRM already holds.
 *
 * The target entity is both filterable and sortable, and either is named by the path the field is read
 * under - `crm.entityScheme`, the closed list of {@see FormDto} - because the question behind a page of
 * forms is regularly "the forms that create a lead" rather than "all of them", and a page of many is
 * regularly read grouped by the entity it creates. Its values are a dictionary a client can read
 * through `crm.form.configuration.get`, so either can be built without guessing.
 *
 * {@see self::$duplicateMode} carries no attribute: it decides what a submission does to an existing
 * record, and it neither narrows nor orders a page of the list.
 *
 * The funnels and the two searches for a duplicate carry no attribute either, and for a reason of
 * the storage rather than of the question: they are stored inside one serialized column of the form,
 * which the database reads as a blob and can neither narrow nor order a page by. Either attribute
 * can be added compatibly later; taken away, it could not.
 *
 * A funnel of null means the form names none. Zero is not that: it is the main funnel of deals.
 */
final class CrmSettingsDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public int $entityScheme;

	public ?string $duplicateMode;

	public ?int $dealCategoryId;

	public bool $dealDuplicateControlEnabled;

	public ?int $smartProcessCategoryId;

	public bool $smartProcessDuplicateControlEnabled;
}
