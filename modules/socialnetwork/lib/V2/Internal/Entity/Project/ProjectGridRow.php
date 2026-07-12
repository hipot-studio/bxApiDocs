<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;

readonly class ProjectGridRow implements Arrayable
{
	public function __construct(
		public ?int $id = null,
		public ?string $name = null,
		public ?Avatar $avatar = null,
		public ?User $owner = null,
		public ?string $dateCreate = null,
		public ?string $dateActivity = null,
		public ?string $activityDate = null,
		public ?string $privacyType = null,
		public ?string $efficiency = null,
		public ?UserCollection $members = null,
		public ?string $role = null,
		public ?int $numberOfMembers = null,
		public ?int $numberOfModerators = null,
		public ?bool $closed = null,
		public ?bool $hasCollabers = null,
		public ?array $tags = null,
		public ?string $dateRelation = null,
		public ?string $dateView = null,
		public ?string $projectDateStart = null,
		public ?string $projectDateFinish = null,
		public ?int $scrumMasterId = null,
		public ?string $type = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'ID' => $this->id,
			'NAME' => $this->name,
			'AVATAR' => $this->avatar,
			'OWNER' => $this->owner,
			'DATE_CREATE' => $this->dateCreate,
			'DATE_ACTIVITY' => $this->dateActivity,
			'ACTIVITY_DATE' => $this->activityDate ?? $this->dateActivity,
			'PRIVACY_TYPE' => $this->privacyType,
			'EFFICIENCY' => $this->efficiency,
			'MEMBERS' => $this->members,
			'ROLE' => $this->role,
			'NUMBER_OF_MEMBERS' => $this->numberOfMembers,
			'NUMBER_OF_MODERATORS' => $this->numberOfModerators,
			'CLOSED' => $this->closed,
			'HAS_COLLABERS' => $this->hasCollabers,
			'TAGS' => $this->tags,
			'DATE_RELATION' => $this->dateRelation,
			'DATE_VIEW' => $this->dateView,
			'PROJECT_DATE_START' => $this->projectDateStart,
			'PROJECT_DATE_FINISH' => $this->projectDateFinish,
			'SCRUM_MASTER_ID' => $this->scrumMasterId,
			'TYPE' => $this->type,
		];
	}
}
