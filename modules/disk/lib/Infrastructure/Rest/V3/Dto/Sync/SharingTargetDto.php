<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * One recipient of the rights of an object. The kinds that name nobody in particular - all
 * authorised users, everyone including anonymous visitors, any other access code - carry no
 * identifier.
 *
 * Property order is the order of the published answer.
 */
final class SharingTargetDto extends Dto
{
	public const TARGET_USER = 'user';
	public const TARGET_WORKGROUP = 'workgroup';
	public const TARGET_DEPARTMENT = 'department';
	public const TARGET_CHAT = 'chat';
	public const TARGET_ALL_USERS = 'allUsers';
	public const TARGET_EVERYONE = 'everyone';
	public const TARGET_OTHER = 'other';

	public string $type;

	public int $id;

	public static function of(string $type, ?int $id = null): self
	{
		$target = new self();
		$target->type = $type;
		if ($id !== null)
		{
			$target->id = $id;
		}

		return $target;
	}
}
