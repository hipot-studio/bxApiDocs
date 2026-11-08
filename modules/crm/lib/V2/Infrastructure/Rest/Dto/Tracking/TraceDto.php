<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\UtmDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * The external contract of a trace of the end-to-end analytics: a visit that brought CRM items.
 *
 * A read answers {@see self::OUTPUT_FIELDS}. The input of `add` takes the source, the visit and the
 * items to bind as flat arrays of identifiers, which are written and never answered. The item a list
 * is read for is a required filter by {@see self::$entityTypeId} and {@see self::$entityId}; neither
 * is a property of the trace, so neither is answered either.
 *
 * MCP describes a nested DTO as a bare object, so the format of `utm`, `pages` and `channels` is
 * written out in their descriptions.
 */
#[Title('CRM tracking trace')]
final class TraceDto extends Dto
{
	public const OUTPUT_FIELDS = ['id', 'sourceId', 'createdTime', 'isMobile', 'channels', 'pages', 'utm'];

	/**
	 * The limits of the input of `add`. The pages are stored serialized in one TEXT column of 64 KB: ten
	 * pages of the longest url and title fit it even in four-byte characters. The tracker of a site keeps
	 * five pages; a channel value and a title are limited by their columns and the legacy views.
	 */
	public const MAX_PAGES = 10;
	public const MAX_PAGE_URL_LENGTH = 1000;
	public const MAX_PAGE_TITLE_LENGTH = 255;
	public const MAX_CHANNELS = 20;
	public const MAX_CHANNEL_VALUE_LENGTH = 255;
	public const MAX_BINDINGS = 50;

	public int $id;

	#[Editable(['add'])]
	#[Required(['add'])]
	#[Description('Identifier of an active tracking source.')]
	public ?int $sourceId;

	public DateTime $createdTime;

	#[Editable(['add'])]
	#[Description('Whether the visit was made from a mobile device. False when omitted.')]
	public ?bool $isMobile;

	#[Editable(['add'])]
	#[ElementType(TraceChannelDto::class)]
	#[Description(
		'Channels of the visit: a list of objects {"code": string, "value": string}. Code is one of: mail, call,'
		. ' imol, site, site24, shop24, crm-shop, site-domain, button, form, callback, rest, order, sales-center,'
		. ' fb-lead-ads, vk-lead-ads. Value is the identifier within the channel, such as a form ID or a phone,'
		. ' at most ' . self::MAX_CHANNEL_VALUE_LENGTH . ' characters. At most ' . self::MAX_CHANNELS . ' channels.'
	)]
	public ?DtoCollection $channels;

	#[Editable(['add'])]
	#[ElementType(TracePageDto::class)]
	#[Description(
		'Visited pages: a list of objects {"url": string, "title": string, "time": string}, at most '
		. self::MAX_PAGES . ' pages. Url is required: an absolute http or https address of at most '
		. self::MAX_PAGE_URL_LENGTH . ' characters. Title is at most ' . self::MAX_PAGE_TITLE_LENGTH
		. ' characters. Time is an ISO 8601 date and time, such as 2026-10-03T10:00:00+03:00.'
	)]
	public ?DtoCollection $pages;

	#[Editable(['add'])]
	#[Description(
		'UTM tags of the visit: an object {"source": string, "medium": string, "campaign": string,'
		. ' "content": string, "term": string}, every key optional. Omit the field when there are no tags.'
	)]
	public ?UtmDto $utm;

	#[Editable(['add'])]
	#[ElementType('int')]
	#[ElementsType(Type::Integer)]
	#[Description(
		'Identifiers of the leads to bind the trace to. At least one binding of any type is required,'
		. ' at most ' . self::MAX_BINDINGS . ' of all types together.'
	)]
	public ?array $leadsId;

	#[Editable(['add'])]
	#[ElementType('int')]
	#[ElementsType(Type::Integer)]
	#[Description(
		'Identifiers of the deals to bind the trace to. At least one binding of any type is required,'
		. ' at most ' . self::MAX_BINDINGS . ' of all types together.'
	)]
	public ?array $dealsId;

	#[Editable(['add'])]
	#[ElementType('int')]
	#[ElementsType(Type::Integer)]
	#[Description(
		'Identifiers of the contacts to bind the trace to. At least one binding of any type is required,'
		. ' at most ' . self::MAX_BINDINGS . ' of all types together.'
	)]
	public ?array $contactsId;

	#[Editable(['add'])]
	#[ElementType('int')]
	#[ElementsType(Type::Integer)]
	#[Description(
		'Identifiers of the companies to bind the trace to. At least one binding of any type is required,'
		. ' at most ' . self::MAX_BINDINGS . ' of all types together.'
	)]
	public ?array $companiesId;

	#[Editable(['add'])]
	#[ElementType('int')]
	#[ElementsType(Type::Integer)]
	#[Description(
		'Identifiers of the quotes to bind the trace to. At least one binding of any type is required,'
		. ' at most ' . self::MAX_BINDINGS . ' of all types together.'
	)]
	public ?array $quotesId;

	#[Filterable]
	#[Description('Filter only: the type of the CRM item whose traces are listed.')]
	public int $entityTypeId;

	#[Filterable]
	#[Description('Filter only: the identifier of the CRM item whose traces are listed.')]
	public int $entityId;

	/**
	 * The base DTO leaves a collection of DTO as it is, and only a JSON encoding of the answer unfolds it;
	 * every other reader of the answer - the in-process transport and MCP - would get objects. The pages
	 * and the channels are unfolded here, so the answer is the same array whoever reads it.
	 */
	public function toArray(bool $rawData = false): array
	{
		$values = parent::toArray($rawData);
		foreach ($values as $name => $value)
		{
			if ($value instanceof DtoCollection)
			{
				$values[$name] = array_map(
					static fn(Dto $item): array => $item->toArray($rawData),
					iterator_to_array($value, false),
				);
			}
		}

		return $values;
	}
}
