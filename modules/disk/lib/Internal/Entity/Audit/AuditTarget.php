<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\Audit;

use InvalidArgumentException;
use JsonSerializable;

final class AuditTarget implements JsonSerializable
{
	public const TYPE_FILE = 'file';
	public const TYPE_FOLDER = 'folder';
	public const TYPE_RIGHTS = 'rights';
	public const TYPE_PUBLIC_LINK = 'public_link';
	public const TYPE_UNIFIED_LINK = 'unified_link';
	public const TYPE_TRASHCAN = 'trashcan';
	public const TYPE_AUDIT_SETTINGS = 'audit_settings';
	public const TYPE_AUDIT_LOG = 'audit_log';

	private const KNOWN_TYPES = [
		self::TYPE_FILE => true,
		self::TYPE_FOLDER => true,
		self::TYPE_RIGHTS => true,
		self::TYPE_PUBLIC_LINK => true,
		self::TYPE_UNIFIED_LINK => true,
		self::TYPE_TRASHCAN => true,
		self::TYPE_AUDIT_SETTINGS => true,
		self::TYPE_AUDIT_LOG => true,
	];

	public function __construct(
		public readonly string $type,
		public readonly string $id,
		public readonly string $name,
		public readonly string $nameSearch,
		public readonly ?string $path = null,
		public readonly ?string $parentId = null,
	)
	{
		$this->assertKnownType($type);
		$this->assertNotEmpty($id, 'id');
		$this->assertNotEmpty($name, 'name');
		$this->assertNotEmpty($nameSearch, 'nameSearch');
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
			nameSearch: self::requireString($data, 'nameSearch'),
			path: self::optionalString($data, 'path'),
			parentId: self::optionalString($data, 'parentId'),
		);
	}

	/**
	 * @return string[]
	 */
	public static function types(): array
	{
		return array_keys(self::KNOWN_TYPES);
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type,
			'id' => $this->id,
			'name' => $this->name,
			'nameSearch' => $this->nameSearch,
			'path' => $this->path,
			'parentId' => $this->parentId,
		];
	}

	private static function assertKnownType(string $type): void
	{
		if (!isset(self::KNOWN_TYPES[$type]))
		{
			throw new InvalidArgumentException('Unknown audit target type.');
		}
	}

	private static function requireString(array $data, string $key): string
	{
		if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '')
		{
			throw new InvalidArgumentException("Audit target field {$key} is required.");
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
			throw new InvalidArgumentException("Audit target field {$key} must be a string.");
		}

		return $data[$key] === '' ? null : $data[$key];
	}

	private function assertNotEmpty(string $value, string $field): void
	{
		if ($value === '')
		{
			throw new InvalidArgumentException("Audit target field {$field} is required.");
		}
	}
}
