<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Dto;

use Bitrix\Calendar\Synchronization\Internal\Exception\DtoValidationException;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * An event found by a filtered events.list request: only the fields needed to recognize it and to keep
 * its Google attendees on the update of the found event.
 *
 * @link https://developers.google.com/calendar/api/v3/reference/events#resource-representations
 */
class EventSearchItemResponse
{
	private const CANCELLED_STATUS = 'cancelled';

	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly ?string $iCalUID,
		public readonly ?DateTimeImmutable $updated,
		public readonly ?string $bxId,
		public readonly ?string $recurringEventId,
		public readonly ?array $attendees = null,
	)
	{
	}

	/**
	 * Builds the item from an element of the "items" list of an events.list response.
	 *
	 * @throws DtoValidationException
	 */
	public static function fromArray(array $data): self
	{
		if (empty($data['id']) || !is_string($data['id']))
		{
			throw new DtoValidationException('Field "id" is required in EventSearchItemResponse DTO');
		}

		$bxId = $data['extendedProperties']['private']['bxId'] ?? null;

		return new self(
			id: $data['id'],
			status: is_string($data['status'] ?? null) ? $data['status'] : '',
			iCalUID: is_string($data['iCalUID'] ?? null) ? $data['iCalUID'] : null,
			updated: self::parseUpdated($data['updated'] ?? null),
			bxId: is_scalar($bxId) ? (string)$bxId : null,
			recurringEventId: is_string($data['recurringEventId'] ?? null) ? $data['recurringEventId'] : null,
			attendees: is_array($data['attendees'] ?? null) ? $data['attendees'] : null,
		);
	}

	private static function parseUpdated(mixed $value): ?DateTimeImmutable
	{
		if (!is_string($value) || $value === '')
		{
			return null;
		}

		try
		{
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
		}
		catch (Exception)
		{
			return null;
		}
	}

	public function isCancelled(): bool
	{
		return $this->status === self::CANCELLED_STATUS;
	}

	public function isInstance(): bool
	{
		return !empty($this->recurringEventId);
	}

	public function isMarkedWithBxId(int $bxId): bool
	{
		return $this->bxId === (string)$bxId;
	}

	/**
	 * Returns the modification time as a string that sorts in time order; an empty string when it is unknown.
	 */
	public function getUpdatedSortKey(): string
	{
		return $this->updated?->format('Y-m-d\TH:i:s.u') ?? '';
	}
}
