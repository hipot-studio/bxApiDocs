<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\LegacyGroup;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

/**
 * Transitional DTO for legacy group types (TYPE=project, TYPE=group) in grid context.
 *
 * After all groups are converted to collabs, delete this class.
 */
class LegacyGroup implements EntityInterface
{
	public function __construct(
		#[PositiveNumber]
		public readonly ?int $id = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: isset($props['id']) ? (int)$props['id'] : null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
		];
	}
}
