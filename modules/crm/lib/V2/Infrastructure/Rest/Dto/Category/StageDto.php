<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Crm\V2\Public\Entity\Category\StageSemantics;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\RegExp;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Attribute\TypeAlias;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a stage of a category (pipeline).
 *
 * The same contract answers a read and carries a write of the single-stage methods. Unlike a
 * category, a stage looks the same whatever the entity type it belongs to, so there is no generator
 * behind this class: the routes of the family name it directly.
 *
 * `stageId` is always the whole identifier, the entity type prefix among it - `C2:NEW` for a Deal
 * category, `DT128_5:NEW` for a smart process one. The forms storage keeps inside itself
 * (`DEAL_STAGE_5`, an identifier without a prefix) are none of a client's business and never reach
 * here. It is read-only: which stage a write means is said by the identifier of the request rather
 * than by a field of it.
 *
 * `categoryId` says which category a stage is added to, and `0` is one of its real values - the
 * default category of Deal. It is not written on an update: a stage does not move between
 * categories. The framework does not enforce that on its own - a field declared required in any
 * group skips the editable check altogether ({@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper}) - so an
 * update sending it is for the controller of the family to refuse.
 *
 * Nothing here is sortable and `categoryId` alone is filterable: the stage provider of the domain
 * reads the stages of one category in the order they stand in and takes neither a filter nor a sort,
 * so publishing either would promise what the scenario does not do. The category is the exception
 * because it is not a filter at all but the address of the set - the list of stages requires it
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\ListStageRequest}), and a filter
 * is where the regulation puts the parent of a list.
 *
 * The name the documentation knows this contract by is spelled out rather than taken from the class:
 * a schema is named after the module and the short class name
 * ({@see \Bitrix\Rest\V3\Dto\Dto::getTypeName()}), and `Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\
 * Common\Dictionary\StageDto` - the stage of an item, a different contract altogether - already
 * answers to `bitrix.crm.stagedto`. Without the alias one of the two would publish the other's fields.
 */
#[Title('CRM pipeline stage')]
#[TypeAlias('CategoryStageDto')]
final class StageDto extends Dto
{
	/**
	 * The colour of a stage as the contract spells it, `#RRGGBB`, or none at all. A stage that has no
	 * colour reads as an empty string, so an empty string is what the contract must take back - and
	 * it is also the only way to ask for the stored colour to be dropped, since a missing field
	 * leaves it alone.
	 *
	 * The end of the string is `\z` rather than `$`: `$` also matches before a trailing newline, and
	 * `"#AABBCC\n"` is not the colour the contract spells out.
	 */
	public const COLOR_PATTERN = '/^(#[0-9A-Fa-f]{6})?\z/';

	/** The name of a stage is kept in `b_crm_status.NAME`. */
	public const NAME_MAX_LENGTH = 100;

	/** The semantics of a stage as the domain names them, never the letters storage keeps them in. */
	private const SEMANTICS = [
		StageSemantics::Process->value,
		StageSemantics::Success->value,
		StageSemantics::Failure->value,
	];

	public string $stageId;

	#[Editable(['add'])]
	#[Required(['add'])]
	#[Filterable]
	public int $categoryId;

	/**
	 * A name of nothing at all is a matter of the form of a request and is refused right here. A name
	 * of nothing but blanks is a matter of the domain, which trims before it looks and answers
	 * {@see \Bitrix\Crm\V2\Public\Entity\Category\CategoryError::FIELD_VALUE_NOT_ALLOWED} - hence
	 * `allowSpaces`, which stops this rule from trimming as well and swallowing that answer. And
	 * `allowZero`, because `"0"` is a name like any other while `empty()` says otherwise.
	 *
	 * A request that sends no name at all reaches neither rule: an uninitialised field is left alone
	 * ({@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper::validate()}), so an update stays partial.
	 */
	#[Editable]
	#[Required(['add'])]
	#[NotEmpty(allowZero: true, allowSpaces: true)]
	#[Length(max: self::NAME_MAX_LENGTH)]
	public string $name;

	#[Editable]
	#[RegExp(self::COLOR_PATTERN)]
	public string $color;

	#[Editable]
	#[InArray(self::SEMANTICS, strict: true, showValues: true)]
	public string $semantics;

	#[Editable]
	public int $sort;

	public bool $isSystem;
}
