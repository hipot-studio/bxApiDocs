<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\Audit;

use InvalidArgumentException;
use JsonSerializable;

final class AuditInitiator implements JsonSerializable
{
	public const TYPE_EMPLOYEE = 'employee';
	public const TYPE_EXTERNAL_USER = 'external_user';
	public const TYPE_APPLICATION = 'application';
	public const TYPE_SYSTEM = 'system';

	private const KNOWN_TYPES = [
		self::TYPE_EMPLOYEE => true,
		self::TYPE_EXTERNAL_USER => true,
		self::TYPE_APPLICATION => true,
		self::TYPE_SYSTEM => true,
	];

	public function __construct(
		public readonly string $type,
		public readonly string $id,
		public readonly string $name,
		public readonly ?string $ip = null,
	)
	{
		$this->assertKnownType($type);
		$this->assertNotEmpty($id, 'id');
		$this->assertNotEmpty($name, 'name');
	}

	public static function createFromData(self|array $data): self
	{
		if ($data instanceof self)
		{
			return $data;
		}

		return new self(
			type: self::requireString($data, 'type'),
			id: self::requireString($data, 'id'),
			name: self::requireString($data, 'name'),
			ip: self::optionalString($data, 'ip'),
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type,
			'id' => $this->id,
			'name' => $this->name,
			'ip' => $this->ip,
		];
	}

	private static function assertKnownType(string $type): void
	{
		if (!isset(self::KNOWN_TYPES[$type]))
		{
			throw new InvalidArgumentException('Unknown audit initiator type.');
		}
	}

	private static function requireString(array $data, string $key): string
	{
		if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '')
		{
			throw new InvalidArgumentException("Audit initiator field {$key} is required.");
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
			throw new InvalidArgumentException("Audit initiator field {$key} must be a string.");
		}

		return $data[$key] === '' ? null : $data[$key];
	}

	private function assertNotEmpty(string $value, string $field): void
	{
		if ($value === '')
		{
			throw new InvalidArgumentException("Audit initiator field {$field} is required.");
		}
	}
}
