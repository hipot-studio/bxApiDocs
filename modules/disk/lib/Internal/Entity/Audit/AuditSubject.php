<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\Audit;

use InvalidArgumentException;
use JsonSerializable;

final class AuditSubject implements JsonSerializable
{
	public function __construct(
		public readonly ?string $type = null,
		public readonly ?string $id = null,
		public readonly ?string $name = null,
	)
	{
	}

	public static function createFromData(self|array|null $data): self
	{
		if ($data instanceof self)
		{
			return $data;
		}

		if ($data === null)
		{
			return new self();
		}

		return new self(
			type: self::optionalString($data, 'type'),
			id: self::optionalString($data, 'id'),
			name: self::optionalString($data, 'name'),
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type,
			'id' => $this->id,
			'name' => $this->name,
		];
	}

	private static function optionalString(array $data, string $key): ?string
	{
		if (!array_key_exists($key, $data) || $data[$key] === null)
		{
			return null;
		}

		if (!is_string($data[$key]))
		{
			throw new InvalidArgumentException("Audit subject field {$key} must be a string.");
		}

		return $data[$key] === '' ? null : $data[$key];
	}
}
