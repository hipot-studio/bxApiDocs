<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use InvalidArgumentException;
use JsonSerializable;

final class AuditSubject implements JsonSerializable
{
	public const TYPE_ACCESS_SUBJECT = 'access_subject';
	public const TYPE_INHERITANCE = 'inheritance';
	public const TYPE_PUBLIC_LINK = 'public_link';
	public const TYPE_UNIFIED_LINK = 'unified_link';
	public const TYPE_STORAGE = 'storage';

	private const KNOWN_TYPES = [
		self::TYPE_ACCESS_SUBJECT => true,
		self::TYPE_INHERITANCE => true,
		self::TYPE_PUBLIC_LINK => true,
		self::TYPE_UNIFIED_LINK => true,
		self::TYPE_STORAGE => true,
	];

	public function __construct(
		public readonly ?string $type = null,
		public readonly ?string $id = null,
	)
	{
		if ($type !== null && !isset(self::KNOWN_TYPES[$type]))
		{
			throw new InvalidArgumentException('Unknown disk audit subject type.');
		}

		if ($id === '')
		{
			throw new InvalidArgumentException('Disk audit subject id must not be empty.');
		}
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type,
			'id' => $this->id,
		];
	}
}
