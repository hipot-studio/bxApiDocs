<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Position;

use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorInvalidException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncRestException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The one signed opaque form behind the three kinds of position of the synchronisation contour.
 * Outwards a position is a string; the client neither reads it nor builds one.
 *
 * Every position names its owner, its expiry and the version of the key that signed it:
 *
 *  - the owner is what makes "someone else's cursor" a decidable question at all - without it the
 *    contour could not tell a position handed to another user, or to another application of the
 *    same user, from its own;
 *  - the expiry inside the position makes an expired cursor decidable regardless of how far the
 *    portion-wise cleaning of the journal has got;
 *  - the key version is what lets a key be rotated without invalidating every live position
 *    (see PositionSignatureKey).
 *
 * Evolution rule for the payload of a kind: a new field is added as an optional one. A live
 * position signed before the field appeared carries no value for it, and the reader has to accept
 * that - a field that becomes required retroactively invalidates every position in flight.
 */
abstract class SignedPosition
{
	public const KIND_SNAPSHOT = 'snapshot';
	public const KIND_JOURNAL = 'journal';
	public const KIND_CHECK = 'check';

	private const VERSION_SEPARATOR = '.';

	/**
	 * Microsecond precision with an explicit offset: the boundaries a position carries are compared
	 * as values, and a boundary rounded to a second would move rows across the edge of a page.
	 */
	private const TIME_FORMAT = 'Y-m-d H:i:s.uP';

	protected function __construct(
		private readonly int $userId,
		private readonly string $appId,
		private readonly DateTime $expiresAt,
	)
	{
	}

	abstract public static function getKind(): string;

	/**
	 * The payload of the kind, beyond the owner, the expiry and the kind itself.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function getPayload(): array;

	/**
	 * @param array<string, mixed> $payload
	 */
	abstract protected static function createFromPayload(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		array $payload,
	): static;

	/**
	 * Expiry is answered per kind: one code for all three would send a client to cure an expired
	 * check token by recreating a session the check neither needs nor touches.
	 */
	abstract protected static function createExpiredException(): SyncRestException;

	final public function getUserId(): int
	{
		return $this->userId;
	}

	final public function getAppId(): string
	{
		return $this->appId;
	}

	final public function getExpiresAt(): DateTime
	{
		return $this->expiresAt;
	}

	final public function encode(): string
	{
		$key = PositionSignatureKey::current();

		$payload = $this->getPayload();
		$payload['kind'] = static::getKind();
		$payload['owner'] = ['userId' => $this->userId, 'appId' => $this->appId];
		$payload['expires'] = $this->expiresAt->getTimestamp();

		$body = base64_encode(serialize($payload));

		return $key->getVersion() . self::VERSION_SEPARATOR . (new Signer())->sign($body, $key->getSalt());
	}

	/**
	 * @throws CursorInvalidException a position of another owner, of another kind, signed by an
	 *         unaccepted key version, or damaged in any way
	 * @throws SyncRestException the expiry of the kind
	 */
	final public static function decode(string $raw, int $userId, string $appId): static
	{
		$payload = self::readPayload($raw);

		if (($payload['kind'] ?? null) !== static::getKind())
		{
			throw new CursorInvalidException();
		}

		$owner = $payload['owner'] ?? null;
		if (
			!is_array($owner)
			|| ($owner['userId'] ?? null) !== $userId
			|| ($owner['appId'] ?? null) !== $appId
		)
		{
			throw new CursorInvalidException();
		}

		$expires = $payload['expires'] ?? null;
		if (!is_int($expires))
		{
			throw new CursorInvalidException();
		}

		if ($expires < time())
		{
			throw static::createExpiredException();
		}

		return static::createFromPayload($userId, $appId, DateTime::createFromTimestamp($expires), $payload);
	}

	final protected static function encodeTime(DateTime $value): string
	{
		return $value->format(self::TIME_FORMAT);
	}

	final protected static function decodeTime(mixed $value): DateTime
	{
		if (!is_string($value))
		{
			throw new CursorInvalidException();
		}

		try
		{
			return new DateTime($value, self::TIME_FORMAT);
		}
		catch (ObjectException)
		{
			throw new CursorInvalidException();
		}
	}

	final protected static function decodeOptionalTime(mixed $value): ?DateTime
	{
		return $value === null ? null : self::decodeTime($value);
	}

	final protected static function decodeInt(mixed $value): int
	{
		if (!is_int($value))
		{
			throw new CursorInvalidException();
		}

		return $value;
	}

	final protected static function decodeOptionalInt(mixed $value): ?int
	{
		return $value === null ? null : self::decodeInt($value);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function readPayload(string $raw): array
	{
		$parts = explode(self::VERSION_SEPARATOR, $raw, 2);
		if (count($parts) !== 2)
		{
			throw new CursorInvalidException();
		}

		[$version, $signedBody] = $parts;

		$key = PositionSignatureKey::findAccepted($version);
		if ($key === null)
		{
			throw new CursorInvalidException();
		}

		try
		{
			$body = (new Signer())->unsign($signedBody, $key->getSalt());
		}
		catch (SystemException)
		{
			throw new CursorInvalidException();
		}

		$serialized = base64_decode($body, true);

		// The body is signature-checked, so only what the portal serialised itself gets this far; the
		// shape check keeps a body that is signed but not a serialised array from reaching unserialize
		// as garbage. allowed_classes is false without exception: the string comes from outside.
		if (!is_string($serialized) || !str_starts_with($serialized, 'a:'))
		{
			throw new CursorInvalidException();
		}

		$payload = unserialize($serialized, ['allowed_classes' => false]);
		if (!is_array($payload))
		{
			throw new CursorInvalidException();
		}

		return $payload;
	}
}
