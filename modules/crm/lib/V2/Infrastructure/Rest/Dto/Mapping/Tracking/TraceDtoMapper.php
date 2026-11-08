<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Tracking;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\UtmDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\TraceChannelDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\TraceDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\TracePageDto;
use Bitrix\Crm\V2\Internal\Entity\Tracking\ChannelCode;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Trace;
use Bitrix\Crm\V2\Internal\Entity\Tracking\TraceChannel;
use Bitrix\Crm\V2\Internal\Entity\Tracking\TracePage;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceChannelInvalidException;
use Bitrix\Crm\V2\Public\Entity\Item\Utm;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * Maps a trace of the domain to its REST contract and the input of `crm.tracking.trace.add` to the
 * trace and the items it is bound to.
 */
final class TraceDtoMapper
{
	/** The input arrays of identifiers => the type of the items they name. */
	private const BINDING_FIELDS = [
		'leadsId' => \CCrmOwnerType::Lead,
		'dealsId' => \CCrmOwnerType::Deal,
		'contactsId' => \CCrmOwnerType::Contact,
		'companiesId' => \CCrmOwnerType::Company,
		'quotesId' => \CCrmOwnerType::Quote,
	];

	/**
	 * @param string[] $select the top-level fields to answer; empty answers every field of a read
	 */
	public function toDto(Trace $trace, array $select = []): TraceDto
	{
		$fields = empty($select) ? TraceDto::OUTPUT_FIELDS : array_intersect(TraceDto::OUTPUT_FIELDS, $select);
		$fields = array_flip($fields);

		$dto = new TraceDto();
		if (isset($fields['id']))
		{
			$dto->id = (int)$trace->id;
		}
		if (isset($fields['sourceId']))
		{
			$dto->sourceId = $trace->getSourceIdOrNull();
		}
		if (isset($fields['createdTime']))
		{
			$dto->createdTime = clone $trace->createdTime;
		}
		if (isset($fields['isMobile']))
		{
			$dto->isMobile = $trace->isMobile;
		}
		if (isset($fields['channels']))
		{
			$dto->channels = $this->mapChannels($trace->channels);
		}
		if (isset($fields['pages']))
		{
			$dto->pages = $this->mapPages($trace->pages);
		}
		if (isset($fields['utm']))
		{
			// A trace without tags answers null, the way the utm of an item does.
			$dto->utm = UtmDto::fromEntity($trace->utm);
		}

		return $dto;
	}

	/**
	 * Reads the trace and its items off the input of `add`, already checked against the DTO.
	 *
	 * The moment of the trace is set by the writer, so the one given here is a placeholder.
	 *
	 * @return array{0: Trace, 1: ItemIdentifier[]}
	 * @throws RequestValidationException no source, no binding, an identifier of an item is not positive, a
	 * page is not a web address or the input is over the limits of {@see TraceDto}
	 * @throws TraceChannelInvalidException a channel code is not one of the known channels
	 */
	public function fromAddDto(TraceDto $dto): array
	{
		$sourceId = $dto->sourceId ?? null;
		if ($sourceId === null)
		{
			throw new RequestValidationException([
				new Error(new LocalizableMessage('CRM_V2_REST_TRACKING_TRACE_SOURCE_REQUIRED'), 'fields.sourceId'),
			]);
		}

		$trace = new Trace(
			id: null,
			sourceId: max(Trace::NO_SOURCE, $sourceId),
			createdTime: new DateTime(),
			isMobile: (bool)($dto->isMobile ?? false),
			pages: $this->readPages($dto->pages ?? null),
			channels: $this->readChannels($dto->channels ?? null),
			utm: $this->readUtm($dto->utm ?? null),
		);

		return [$trace, $this->readItems($dto)];
	}

	/**
	 * @param TraceChannel[] $channels
	 */
	private function mapChannels(array $channels): DtoCollection
	{
		$collection = new DtoCollection(TraceChannelDto::class);
		foreach ($channels as $channel)
		{
			$dto = new TraceChannelDto();
			$dto->code = $channel->code->value;
			$dto->value = $channel->value;
			$collection->add($dto);
		}

		return $collection;
	}

	/**
	 * @param TracePage[] $pages
	 */
	private function mapPages(array $pages): DtoCollection
	{
		$collection = new DtoCollection(TracePageDto::class);
		foreach ($pages as $page)
		{
			$dto = new TracePageDto();
			$dto->url = $page->url;
			$dto->title = $page->title;
			$dto->time = $page->time === null ? null : clone $page->time;
			$collection->add($dto);
		}

		return $collection;
	}

	/**
	 * A page is shown as a link by the views of legacy, so only a web address is taken.
	 *
	 * @return TracePage[]
	 * @throws RequestValidationException too many pages, an address that is not http(s) or a value too long
	 */
	private function readPages(?DtoCollection $pages): array
	{
		$result = [];
		foreach ($pages ?? [] as $page)
		{
			/** @var TracePageDto $page */
			$title = $page->title ?? null;
			if (count($result) >= TraceDto::MAX_PAGES)
			{
				throw self::invalid('fields.pages', 'CRM_V2_REST_TRACKING_TRACE_PAGES_TOO_MANY', TraceDto::MAX_PAGES);
			}
			if (mb_strlen($page->url) > TraceDto::MAX_PAGE_URL_LENGTH)
			{
				throw self::invalid(
					'fields.pages',
					'CRM_V2_REST_TRACKING_TRACE_PAGE_URL_TOO_LONG',
					TraceDto::MAX_PAGE_URL_LENGTH,
				);
			}
			if (!self::isWebAddress($page->url))
			{
				throw self::invalid('fields.pages', 'CRM_V2_REST_TRACKING_TRACE_PAGE_URL_INVALID');
			}
			if ($title !== null && mb_strlen($title) > TraceDto::MAX_PAGE_TITLE_LENGTH)
			{
				throw self::invalid(
					'fields.pages',
					'CRM_V2_REST_TRACKING_TRACE_PAGE_TITLE_TOO_LONG',
					TraceDto::MAX_PAGE_TITLE_LENGTH,
				);
			}

			$result[] = new TracePage($page->url, $title, $page->time ?? null);
		}

		return $result;
	}

	private static function isWebAddress(string $url): bool
	{
		$parts = parse_url($url);
		if (!is_array($parts))
		{
			return false;
		}

		$scheme = mb_strtolower((string)($parts['scheme'] ?? ''));

		return in_array($scheme, ['http', 'https'], true) && ($parts['host'] ?? '') !== '';
	}

	private static function invalid(string $field, string $code, ?int $max = null): RequestValidationException
	{
		$replacements = $max === null ? null : ['#MAX#' => (string)$max];

		return new RequestValidationException([new Error(new LocalizableMessage($code, $replacements), $field)]);
	}

	/**
	 * @return TraceChannel[]
	 * @throws TraceChannelInvalidException
	 * @throws RequestValidationException too many channels or a value too long
	 */
	private function readChannels(?DtoCollection $channels): array
	{
		$result = [];
		foreach ($channels ?? [] as $channel)
		{
			/** @var TraceChannelDto $channel */
			if (count($result) >= TraceDto::MAX_CHANNELS)
			{
				throw self::invalid(
					'fields.channels',
					'CRM_V2_REST_TRACKING_TRACE_CHANNELS_TOO_MANY',
					TraceDto::MAX_CHANNELS,
				);
			}

			$code = ChannelCode::tryFrom($channel->code);
			if ($code === null)
			{
				throw new TraceChannelInvalidException($channel->code);
			}

			$value = (string)($channel->value ?? '');
			if (mb_strlen($value) > TraceDto::MAX_CHANNEL_VALUE_LENGTH)
			{
				throw self::invalid(
					'fields.channels',
					'CRM_V2_REST_TRACKING_TRACE_CHANNEL_VALUE_TOO_LONG',
					TraceDto::MAX_CHANNEL_VALUE_LENGTH,
				);
			}

			$result[] = new TraceChannel($code, $value);
		}

		return $result;
	}

	private function readUtm(?UtmDto $dto): ?Utm
	{
		if ($dto === null)
		{
			return null;
		}

		$values = [
			'source' => $dto->source ?? null,
			'medium' => $dto->medium ?? null,
			'campaign' => $dto->campaign ?? null,
			'content' => $dto->content ?? null,
			'term' => $dto->term ?? null,
		];
		$values = array_filter($values, static fn(?string $value): bool => $value !== null && $value !== '');
		if (empty($values))
		{
			return null;
		}

		return (new Utm())
			->setSource($values['source'] ?? null)
			->setMedium($values['medium'] ?? null)
			->setCampaign($values['campaign'] ?? null)
			->setContent($values['content'] ?? null)
			->setTerm($values['term'] ?? null)
		;
	}

	/**
	 * @return ItemIdentifier[]
	 * @throws RequestValidationException
	 */
	private function readItems(TraceDto $dto): array
	{
		$items = [];
		foreach (self::BINDING_FIELDS as $field => $entityTypeId)
		{
			foreach ($dto->{$field} ?? [] as $entityId)
			{
				if (!is_int($entityId) || $entityId <= 0)
				{
					throw new RequestValidationException([
						new Error(
							new LocalizableMessage('CRM_V2_REST_TRACKING_TRACE_BINDING_INVALID'),
							'fields.' . $field,
						),
					]);
				}

				$items[] = new ItemIdentifier($entityTypeId, $entityId);
			}
		}

		if (empty($items))
		{
			throw new RequestValidationException([
				new Error(new LocalizableMessage('CRM_V2_REST_TRACKING_TRACE_BINDING_REQUIRED'), 'fields'),
			]);
		}
		if (count($items) > TraceDto::MAX_BINDINGS)
		{
			throw self::invalid('fields', 'CRM_V2_REST_TRACKING_TRACE_BINDINGS_TOO_MANY', TraceDto::MAX_BINDINGS);
		}

		return $items;
	}
}
