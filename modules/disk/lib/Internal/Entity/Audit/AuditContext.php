<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\Audit;

use InvalidArgumentException;
use JsonSerializable;

final class AuditContext implements JsonSerializable
{
	public const TYPE_DISK = 'disk';
	public const TYPE_TASK = 'task';
	public const TYPE_CHAT = 'chat';
	public const TYPE_CRM = 'crm';
	public const TYPE_BIZPROC = 'bizproc';
	public const TYPE_SYSTEM = 'system';

	private const KNOWN_TYPES = [
		self::TYPE_DISK => true,
		self::TYPE_TASK => true,
		self::TYPE_CHAT => true,
		self::TYPE_CRM => true,
		self::TYPE_BIZPROC => true,
		self::TYPE_SYSTEM => true,
	];

	public function __construct(
		public readonly string $type,
		public readonly ?string $entityType = null,
		public readonly ?string $entityId = null,
		public readonly ?string $entityName = null,
	)
	{
		$this->assertKnownType($type);
	}

	public static function createFromData(self|array $data): self
	{
		if ($data instanceof self)
		{
			return $data;
		}

		return new self(
			type: self::requireString($data, 'type'),
			entityType: self::optionalString($data, 'entityType'),
			entityId: self::optionalString($data, 'entityId'),
			entityName: self::optionalString($data, 'entityName'),
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type,
			'entityType' => $this->entityType,
			'entityId' => $this->entityId,
			'entityName' => $this->entityName,
		];
	}

	private static function assertKnownType(string $type): void
	{
		if (!isset(self::KNOWN_TYPES[$type]))
		{
			throw new InvalidArgumentException('Unknown audit context type.');
		}
	}

	private static function requireString(array $data, string $key): string
	{
		if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '')
		{
			throw new InvalidArgumentException("Audit context field {$key} is required.");
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
			throw new InvalidArgumentException("Audit context field {$key} must be a string.");
		}

		return $data[$key] === '' ? null : $data[$key];
	}
}
