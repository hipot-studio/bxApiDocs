<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\Invitation\Messenger\Message;

use Bitrix\Calendar\ICal\MailInvitation\InvitationInfo;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;

class EmailInvitationMessage extends AbstractMessage
{
	private const UNUSABLE_PAYLOAD_MESSAGE = 'Email invitation payload holds no usable value';

	private const DELIVERABLE_TYPES = [
		InvitationInfo::TYPE_REQUEST,
		InvitationInfo::TYPE_EDIT,
		InvitationInfo::TYPE_CANCEL,
	];

	/**
	 * @param string[] $changedFieldKeys
	 * @param int $deliveryAttempt Survives requeue: the payload is re-encoded on every reject.
	 */
	public function __construct(
		public readonly int $eventId,
		public readonly int $addresserId,
		public readonly int $receiverId,
		public readonly string $type,
		public readonly array $changedFieldKeys = [],
		public int $deliveryAttempt = 0,
	)
	{
	}

	public function registerDeliveryAttempt(): int
	{
		return ++$this->deliveryAttempt;
	}

	public function jsonSerialize(): mixed
	{
		return [
			'eventId' => $this->eventId,
			'addresserId' => $this->addresserId,
			'receiverId' => $this->receiverId,
			'type' => $this->type,
			'changedFieldKeys' => $this->changedFieldKeys,
			'deliveryAttempt' => $this->deliveryAttempt,
		];
	}

	/**
	 * The repository isolates only ArgumentException: a TypeError raised here would escape the
	 * whole batch read, so a single damaged row would stall the queue for good. Reported as an
	 * argument failure, the row is dropped instead and the rest of the queue keeps moving.
	 *
	 * A payload decoded into a scalar is caught inside the method for that very reason: declared
	 * as an array, the parameter would raise that TypeError before the body is ever entered.
	 *
	 * @throws ArgumentException
	 */
	public static function createFromData(mixed $data): MessageInterface
	{
		if (!is_array($data))
		{
			throw new ArgumentException(self::UNUSABLE_PAYLOAD_MESSAGE, 'data');
		}

		return new static(
			eventId: self::readInt($data, 'eventId'),
			addresserId: self::readInt($data, 'addresserId'),
			receiverId: self::readInt($data, 'receiverId'),
			type: self::readType($data),
			changedFieldKeys: self::readChangedFieldKeys($data),
			deliveryAttempt: self::readDeliveryAttempt($data),
		);
	}

	/**
	 * @throws ArgumentException
	 */
	private static function readInt(array $data, string $key): int
	{
		$value = $data[$key] ?? null;

		if (!self::isIntLike($value))
		{
			throw new ArgumentException(self::UNUSABLE_PAYLOAD_MESSAGE, $key);
		}

		return (int)$value;
	}

	/**
	 * The value is weighed against the types an invitation can be assembled from and not merely
	 * against the shape of a string: an unknown type builds no sender at all, and the receiver reads
	 * that as a delivery that failed and tells the organizer their guest was never invited. A damaged
	 * row must not become that notice - reported here as an argument failure it is dropped along with
	 * the rest of the unusable payloads, and nobody hears about a delivery never attempted.
	 *
	 * @throws ArgumentException
	 */
	private static function readType(array $data): string
	{
		$type = $data['type'] ?? null;

		if (!is_string($type) || !in_array($type, self::DELIVERABLE_TYPES, true))
		{
			throw new ArgumentException(self::UNUSABLE_PAYLOAD_MESSAGE, 'type');
		}

		return $type;
	}

	/**
	 * @return string[]
	 *
	 * @throws ArgumentException
	 */
	private static function readChangedFieldKeys(array $data): array
	{
		$value = $data['changedFieldKeys'] ?? [];

		if (!is_array($value))
		{
			throw new ArgumentException(self::UNUSABLE_PAYLOAD_MESSAGE, 'changedFieldKeys');
		}

		$keys = [];
		foreach ($value as $key)
		{
			if (!is_string($key) && !is_int($key))
			{
				throw new ArgumentException(self::UNUSABLE_PAYLOAD_MESSAGE, 'changedFieldKeys');
			}

			$keys[] = (string)$key;
		}

		return $keys;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function readDeliveryAttempt(array $data): int
	{
		if (!isset($data['deliveryAttempt']))
		{
			return 0;
		}

		return max(0, self::readInt($data, 'deliveryAttempt'));
	}

	private static function isIntLike(mixed $value): bool
	{
		return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
	}
}
