<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Dto;

use Bitrix\Calendar\Synchronization\Internal\Exception\DtoValidationException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;

/**
 * One page of a filtered events.list response. Unlike EventListResponse it does not require
 * a sync token: filtered requests are not incremental.
 *
 * @link https://developers.google.com/calendar/api/v3/reference/events/list#response
 */
class EventSearchPageResponse
{
	/**
	 * @param EventSearchItemResponse[] $items
	 */
	public function __construct(
		public readonly array $items,
		public readonly ?string $nextPageToken,
	)
	{
	}

	/**
	 * @throws DtoValidationException
	 */
	public static function fromJson(string $jsonResponse): self
	{
		try
		{
			$data = Json::decode($jsonResponse);
		}
		catch (ArgumentException $e)
		{
			throw new DtoValidationException(
				sprintf('Wrong JSON from Google: "%s"', $e->getMessage()),
				previous: $e
			);
		}

		if (!is_array($data) || ($data['kind'] ?? null) !== 'calendar#events')
		{
			throw new DtoValidationException('Data type should be "calendar#events"');
		}

		$items = [];

		foreach ($data['items'] ?? [] as $item)
		{
			if (!is_array($item))
			{
				throw new DtoValidationException('Each item of EventSearchPageResponse DTO should be an object');
			}

			$items[] = EventSearchItemResponse::fromArray($item);
		}

		$nextPageToken = $data['nextPageToken'] ?? null;

		return new self(
			items: $items,
			nextPageToken: is_string($nextPageToken) && $nextPageToken !== '' ? $nextPageToken : null,
		);
	}
}
