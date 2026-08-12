<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

/**
 * DTO-02: notifications settings in the project update/create payload.
 *
 * {"notifications": {"types": [{"id": "...", "counterEnabled": true}, ...]}}
 *
 * Unknown or invalid type ids are silently discarded (defensive).
 */
class NotificationsInput
{
	use MapTypeTrait;

	/** @var NotificationTypeInput[] */
	public readonly array $types;

	public function __construct(array $types = [])
	{
		$this->types = $types;
	}

	public static function mapFromArray(array $props): static
	{
		$rawTypes = $props['types'] ?? null;
		$types = [];

		if (is_array($rawTypes))
		{
			foreach ($rawTypes as $rawItem)
			{
				if (!is_array($rawItem))
				{
					continue;
				}

				$item = NotificationTypeInput::mapFromArray($rawItem);
				if ($item->id !== null && $item->id !== '' && $item->counterEnabled !== null)
				{
					$types[] = $item;
				}
			}
		}

		return new static($types);
	}
}
