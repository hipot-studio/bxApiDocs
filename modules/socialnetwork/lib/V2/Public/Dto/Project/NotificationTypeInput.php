<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

/**
 * DTO-02 item: one notification type setting in the project update/create payload.
 *
 * {"id": "member_add_employee", "counterEnabled": true}
 */
class NotificationTypeInput
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?string $id = null,
		public readonly ?bool $counterEnabled = null,
	)
	{
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapString($props, 'id'),
			counterEnabled: static::mapBool($props, 'counterEnabled'),
		);
	}
}
