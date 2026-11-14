<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\Audit;

use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;

final class AuditEvent
{
	public readonly DateTime $occurredAt;

	public function __construct(
		public readonly string $source,
		public readonly string $operationId,
		public readonly string $eventCode,
		DateTime $occurredAt,
		public readonly ?int $storageId = null,
		public readonly ?string $storageName = null,
		public readonly string $result = AuditResult::SUCCESS,
	)
	{
		$this->assertNotEmpty($source, 'source');
		$this->assertNotEmpty($operationId, 'operationId');
		$this->assertNotEmpty($eventCode, 'eventCode');
		AuditResult::assertKnown($result);

		$this->occurredAt = clone $occurredAt;
	}

	public static function createFromData(self|array $data): self
	{
		if ($data instanceof self)
		{
			return $data;
		}

		return new self(
			source: self::requireString($data, 'source'),
			operationId: self::requireString($data, 'operationId'),
			eventCode: self::requireString($data, 'eventCode'),
			occurredAt: self::restoreDateTime($data['occurredAt'] ?? null),
			storageId: self::optionalInt($data, 'storageId'),
			storageName: self::optionalString($data, 'storageName'),
			result: self::optionalString($data, 'result') ?? AuditResult::SUCCESS,
		);
	}

	private static function restoreDateTime(mixed $value): DateTime
	{
		if ($value instanceof DateTime)
		{
			return clone $value;
		}

		if ($value instanceof \DateTime)
		{
			return DateTime::createFromPhp($value);
		}

		if (is_array($value) && isset($value['date']) && is_string($value['date']))
		{
			$timezone = null;
			if (isset($value['timezone']) && is_string($value['timezone']))
			{
				$timezone = new \DateTimeZone($value['timezone']);
			}

			return DateTime::createFromPhp(new \DateTime($value['date'], $timezone));
		}

		if (is_string($value) && $value !== '')
		{
			return DateTime::createFromPhp(new \DateTime($value));
		}

		throw new InvalidArgumentException('Audit occurredAt is required.');
	}

	private static function requireString(array $data, string $key): string
	{
		if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '')
		{
			throw new InvalidArgumentException("Audit event field {$key} is required.");
		}

		return $data[$key];
	}

	private static function optionalString(array $data, string $key): ?string
	{
		if (!array_key_exists($key, $data) || $data[$key] === null)
		{
			return null;
		}

		if (!is_string($data[$key]))
		{
			throw new InvalidArgumentException("Audit event field {$key} must be a string.");
		}

		return $data[$key] === '' ? null : $data[$key];
	}

	private static function requireInt(array $data, string $key): int
	{
		if (!array_key_exists($key, $data) || !is_int($data[$key]))
		{
			throw new InvalidArgumentException("Audit event field {$key} is required.");
		}

		return $data[$key];
	}

	private static function optionalInt(array $data, string $key): ?int
	{
		if (!array_key_exists($key, $data) || $data[$key] === null)
		{
			return null;
		}

		if (!is_int($data[$key]))
		{
			throw new InvalidArgumentException("Audit event field {$key} must be an integer.");
		}

		return $data[$key];
	}

	private function assertNotEmpty(string $value, string $field): void
	{
		if ($value === '')
		{
			throw new InvalidArgumentException("Audit event field {$field} is required.");
		}
	}
}
