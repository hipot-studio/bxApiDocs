<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Scrum;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;

readonly class ScrumGridRow implements Arrayable
{
	public function __construct(
		public ?int $id = null,
		public ?string $name = null,
		public ?File $image = null,
		public ?User $owner = null,
		public ?string $dateCreate = null,
		public ?string $dateActivity = null,
		public ?string $activityDate = null,
		public ?string $privacyType = null,
		public ?UserCollection $members = null,
		public ?string $role = null,
		public ?int $numberOfMembers = null,
		public ?int $numberOfModerators = null,
		public ?bool $closed = null,
		public ?int $scrumMasterId = null,
		public ?array $tags = null,
		public ?string $dateRelation = null,
		public ?string $dateView = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'ID' => $this->id,
			'NAME' => $this->name,
			'IMAGE' => $this->image,
			'OWNER' => $this->owner,
			'DATE_CREATE' => $this->dateCreate,
			'DATE_ACTIVITY' => $this->dateActivity,
			'ACTIVITY_DATE' => $this->activityDate ?? $this->dateActivity,
			'PRIVACY_TYPE' => $this->privacyType,
			'MEMBERS' => $this->members,
			'ROLE' => $this->role,
			'NUMBER_OF_MEMBERS' => $this->numberOfMembers,
			'NUMBER_OF_MODERATORS' => $this->numberOfModerators,
			'CLOSED' => $this->closed,
			'SCRUM_MASTER_ID' => $this->scrumMasterId,
			'TYPE' => Type::Scrum->value,
			'TAGS' => $this->tags,
			'DATE_RELATION' => $this->dateRelation,
			'DATE_VIEW' => $this->dateView,
		];
	}
}
