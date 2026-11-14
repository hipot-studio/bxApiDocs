<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use Bitrix\Disk\Internal\Entity\Audit\AuditChannel;
use Bitrix\Disk\Internal\Entity\Audit\AuditResult;
use Bitrix\Disk\Internal\Entity\Audit\AuditTarget;
use Bitrix\Disk\Internal\Service\Audit\AuditEventCodeRegistry;
use Bitrix\Disk\Internal\Service\Audit\AuditSnapshotBuilder;
use Bitrix\Main\Type\DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final class AuditFilter
{
	private const MAX_ARRAY_SIZE = 50;
	private const MAX_INPUT_SIZE = 16 * 1024;
	private const MAX_SEARCH_LENGTH = 255;
	private const MAX_PAGE_SIZE = 100;

	/** @var AuditInitiatorFilter[]|null */
	public readonly ?array $initiator;
	/** @var string[]|null */
	public readonly ?array $eventCode;
	/** @var string[]|null */
	public readonly ?array $targetType;
	/** @var int[]|null */
	public readonly ?array $storageId;
	/** @var string[]|null */
	public readonly ?array $channel;
	/** @var string[]|null */
	public readonly ?array $result;

	private function __construct(
		public readonly ?DateTime $from,
		public readonly ?DateTime $to,
		?array $initiator,
		?array $eventCode,
		?array $targetType,
		?array $storageId,
		?array $channel,
		?array $result,
		public readonly ?string $search,
		public readonly int $page,
		public readonly int $pageSize,
	)
	{
		$this->initiator = $initiator;
		$this->eventCode = $eventCode;
		$this->targetType = $targetType;
		$this->storageId = $storageId;
		$this->channel = $channel;
		$this->result = $result;
	}

	public static function createFromArray(
		array $data,
		DateTimeZone $administratorTimeZone,
		?DateTimeZone $serverTimeZone = null,
	): self
	{
		self::assertInputSize($data);

		$serverTimeZone ??= new DateTimeZone(date_default_timezone_get());
		$from = self::normalizeDate($data['from'] ?? null, $administratorTimeZone, $serverTimeZone, 'from');
		$to = self::normalizeDate($data['to'] ?? null, $administratorTimeZone, $serverTimeZone, 'to');

		if ($from !== null && $to !== null && $from->getTimestamp() > $to->getTimestamp())
		{
			throw new InvalidArgumentException('Disk audit period start must not be later than its end.');
		}

		$page = self::normalizeInteger($data['page'] ?? 1, 'page');
		$pageSize = self::normalizeInteger($data['pageSize'] ?? 20, 'pageSize');
		if ($page < 1)
		{
			throw new InvalidArgumentException('Disk audit page must be positive.');
		}
		if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE)
		{
			throw new InvalidArgumentException('Disk audit page size must contain from 1 to 100 items.');
		}

		return new self(
			from: $from,
			to: $to,
			initiator: self::normalizeInitiators($data),
			eventCode: self::normalizeStrings(
				$data,
				'eventCode',
				static fn(string $value): bool => AuditEventCodeRegistry::hasFilterCode($value),
			),
			targetType: self::normalizeStrings(
				$data,
				'targetType',
				static fn(string $value): bool => in_array($value, AuditTarget::types(), true),
			),
			storageId: self::normalizeStorageIds($data),
			channel: self::normalizeStrings(
				$data,
				'channel',
				static function(string $value): bool {
					AuditChannel::assertKnown($value);

					return true;
				},
			),
			result: self::normalizeStrings(
				$data,
				'result',
				static function(string $value): bool {
					AuditResult::assertKnown($value);

					return true;
				},
			),
			search: self::normalizeSearch($data['search'] ?? null),
			page: $page,
			pageSize: $pageSize,
		);
	}

	private static function assertInputSize(array $data): void
	{
		try
		{
			$serialized = json_encode($data, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $exception)
		{
			throw new InvalidArgumentException('Disk audit filter cannot be serialized.', previous: $exception);
		}

		if (strlen($serialized) > self::MAX_INPUT_SIZE)
		{
			throw new InvalidArgumentException('Disk audit filter exceeds 16 KiB.');
		}
	}

	private static function normalizeDate(
		mixed $value,
		DateTimeZone $administratorTimeZone,
		DateTimeZone $serverTimeZone,
		string $field,
	): ?DateTime
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		if (!is_string($value))
		{
			throw new InvalidArgumentException("Disk audit {$field} must be a date string.");
		}

		$value = trim($value);
		if ($value === '')
		{
			return null;
		}

		$formats = [
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d',
			'Y-m-d\\TH:i:sP',
			'Y-m-d\\TH:i:s',
			'Y-m-d\\TH:iP',
			'Y-m-d\\TH:i',
		];
		$parsed = null;
		foreach ($formats as $format)
		{
			$candidate = DateTimeImmutable::createFromFormat('!' . $format, $value, $administratorTimeZone);
			$errors = DateTimeImmutable::getLastErrors();
			if ($candidate !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)))
			{
				$parsed = $candidate;

				break;
			}
		}

		if (!$parsed instanceof DateTimeImmutable)
		{
			throw new InvalidArgumentException("Disk audit {$field} has an invalid date format.");
		}

		$parsed = $parsed->setTimezone($serverTimeZone);

		return new DateTime($parsed->format('Y-m-d H:i:s'), 'Y-m-d H:i:s', $serverTimeZone);
	}

	private static function normalizeInitiators(array $data): ?array
	{
		if (!array_key_exists('initiator', $data))
		{
			return null;
		}

		$values = self::requireLimitedArray($data['initiator'], 'initiator');

		return array_map(
			static fn(mixed $value): AuditInitiatorFilter => is_array($value) || $value instanceof AuditInitiatorFilter
				? AuditInitiatorFilter::createFromArray($value)
				: throw new InvalidArgumentException('Disk audit initiator filter must be an array.'),
			$values,
		);
	}

	private static function normalizeStrings(array $data, string $field, callable $isAllowed): ?array
	{
		if (!array_key_exists($field, $data))
		{
			return null;
		}

		$values = self::requireLimitedArray($data[$field], $field);
		foreach ($values as $value)
		{
			if (!is_string($value) || $value === '' || !$isAllowed($value))
			{
				throw new InvalidArgumentException("Unknown disk audit {$field} value.");
			}
		}

		return array_values($values);
	}

	private static function normalizeStorageIds(array $data): ?array
	{
		if (!array_key_exists('storageId', $data))
		{
			return null;
		}

		$values = self::requireLimitedArray($data['storageId'], 'storageId');
		$normalized = [];
		foreach ($values as $value)
		{
			$id = self::normalizeInteger($value, 'storageId');
			if ($id < 1)
			{
				throw new InvalidArgumentException('Disk audit storage ID must be positive.');
			}
			$normalized[] = $id;
		}

		return $normalized;
	}

	private static function requireLimitedArray(mixed $value, string $field): array
	{
		if (!is_array($value))
		{
			throw new InvalidArgumentException("Disk audit {$field} filter must be an array.");
		}

		if (count($value) > self::MAX_ARRAY_SIZE)
		{
			throw new InvalidArgumentException("Disk audit {$field} filter cannot contain more than 50 values.");
		}

		return $value;
	}

	private static function normalizeSearch(mixed $value): ?string
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		if (!is_string($value))
		{
			throw new InvalidArgumentException('Disk audit search must be a string.');
		}
		if (mb_strlen($value) > self::MAX_SEARCH_LENGTH)
		{
			throw new InvalidArgumentException('Disk audit search cannot exceed 255 characters.');
		}

		$normalized = (new AuditSnapshotBuilder())->normalizeNameForSearch($value);
		if ($normalized === '')
		{
			return null;
		}

		return addcslashes($normalized, '\\%_');
	}

	private static function normalizeInteger(mixed $value, string $field): int
	{
		if (is_int($value))
		{
			return $value;
		}

		if (!is_string($value) || !preg_match('/^-?\d+$/D', $value))
		{
			throw new InvalidArgumentException("Disk audit {$field} must be an integer.");
		}

		$normalized = filter_var($value, FILTER_VALIDATE_INT);
		if ($normalized === false)
		{
			throw new InvalidArgumentException("Disk audit {$field} must be an integer.");
		}

		return $normalized;
	}

}
