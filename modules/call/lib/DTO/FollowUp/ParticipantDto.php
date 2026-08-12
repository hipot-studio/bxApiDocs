<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

class ParticipantDto extends Dto
{
	use NullCompactArrayTrait;

	#[Description('Bitrix24 user id.')]
	public ?int $userId = null;

	#[Description('Time the user spent in the call (LAST_SEEN - FIRST_JOINED), in seconds. Null if join/leave timestamps are missing.')]
	public ?int $talkedSeconds = null;

	#[Description('Composed display name from Bitrix\\Im\\V2\\Entity\\User\\UserCollection. Falls back to login when first/last names are unavailable.')]
	public ?string $name = null;

	#[Description('Resolved avatar URL (preview-sized, EXACT resize). Null when the user has no photo set.')]
	public ?string $avatar = null;

	#[Description('User job title from b_user.WORK_POSITION. Null when not set.')]
	public ?string $workPosition = null;
}
