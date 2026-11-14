<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use Bitrix\Disk\Internal\Entity\Audit\AuditInitiator;
use InvalidArgumentException;

final class AuditInitiatorFilter
{
	public readonly string $type;
	public readonly ?string $id;

	public function __construct(string $type, ?string $id = null)
	{
		if (!in_array($type, self::getAllowedTypes(), true))
		{
			throw new InvalidArgumentException('Unknown disk audit initiator type.');
		}

		$id = $id !== null ? trim($id) : null;
		if ($id !== null && ($id === '' || mb_strlen($id) > 64))
		{
			throw new InvalidArgumentException('Disk audit initiator ID must contain from 1 to 64 characters.');
		}

		$this->type = $type;
		$this->id = $id;
	}

	public static function createFromArray(self|array $data): self
	{
		if ($data instanceof self)
		{
			return $data;
		}

		if (!isset($data['initiatorType']))
		{
			throw new InvalidArgumentException('Disk audit initiator type is required.');
		}

		$initiatorId = $data['initiatorId'] ?? null;
		if (!is_string($data['initiatorType']) || ($initiatorId !== null && !is_string($initiatorId)))
		{
			throw new InvalidArgumentException('Disk audit initiator type and ID must be strings when provided.');
		}

		return new self($data['initiatorType'], $initiatorId);
	}

	private static function getAllowedTypes(): array
	{
		return [
			AuditInitiator::TYPE_EMPLOYEE,
			AuditInitiator::TYPE_EXTERNAL_USER,
			AuditInitiator::TYPE_APPLICATION,
			AuditInitiator::TYPE_SYSTEM,
		];
	}
}
