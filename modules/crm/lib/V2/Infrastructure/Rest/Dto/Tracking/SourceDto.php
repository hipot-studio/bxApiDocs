<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a source of the end-to-end analytics: a built-in source of the product or an own
 * source of the portal.
 *
 * A write takes the name, the color and the two lists; a field that is not passed keeps its value. The lists
 * are replaced as a whole. The rest is answered by a read and is never written.
 */
#[Title('CRM tracking source')]
final class SourceDto extends Dto
{
	public const OUTPUT_FIELDS = [
		'id',
		'code',
		'kind',
		'name',
		'color',
		'isActive',
		'isConfigurable',
		'isConfigured',
		'refDomains',
		'utmSources',
	];

	public const KIND_BUILT_IN = 'builtIn';
	public const KIND_OWN = 'own';

	#[Description('Identifier of the record of the source. Null for a built-in source that has never been set up.')]
	public ?int $id;

	#[Description(
		'Code of a built-in source: google, fb, instagram, vkads, vk, yandex, 1c, avito, organic or sender-mail.'
		. ' Null for an own source.'
	)]
	public ?string $code;

	#[Description(
		'Kind of the source: ' . self::KIND_BUILT_IN . ' - provided by the product, ' . self::KIND_OWN
		. ' - created on the portal.'
	)]
	public string $kind;

	#[Editable(['add', 'update'])]
	#[Required(['add'])]
	#[Description('Name of the source.')]
	public string $name;

	#[Editable(['add', 'update'])]
	#[Description(
		'Color of an own source: #rgb or #rrggbb. The color of a built-in source belongs to the product and is'
		. ' not set.'
	)]
	public ?string $color;

	#[Filterable]
	#[Description(
		'Whether the source is active. The list gives the active sources; the filter isActive = false gives'
		. ' the archived ones instead.'
	)]
	public bool $isActive;

	#[Description('Whether the source can be set up: false for organic traffic and email marketing.')]
	public bool $isConfigurable;

	#[Description('Whether the source is set up: it has a UTM source or a referring domain.')]
	public bool $isConfigured;

	#[Editable(['add', 'update'])]
	#[ElementType('string')]
	#[ElementsType(Type::String)]
	#[Description(
		'Referring domains the visits of the source are recognized by. For google, yandex, fb, instagram, vk'
		. ' and vkads the domains belong to the product and are not set. A write replaces the whole list:'
		. ' values are trimmed, empty ones are dropped; more than 100 values are refused.'
	)]
	public array $refDomains;

	#[Editable(['add', 'update'])]
	#[ElementType('string')]
	#[ElementsType(Type::String)]
	#[Description(
		'Values of utm_source the visits of the source are recognized by. A write replaces the whole list:'
		. ' values are trimmed, empty ones are dropped; more than 100 values are refused.'
	)]
	public array $utmSources;
}
