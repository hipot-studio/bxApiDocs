<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Mapper;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectResponse;

class ProjectMapper
{
	private readonly ProjectMemberMapper $projectMemberMapper;
	private readonly ProjectAvatarMapper $projectAvatarMapper;

	public function __construct(
	)
	{
		$this->projectMemberMapper = new ProjectMemberMapper();
		$this->projectAvatarMapper = new ProjectAvatarMapper();
	}

	public function toResponse(Project $entity): ProjectResponse
	{
		$members = $this->projectMemberMapper->mapToDtoCollection($entity->members)?->toArray();
		$moderatorMembers = $this->projectMemberMapper->mapToDtoCollection($entity->moderatorMembers)?->toArray();

		return new ProjectResponse(
			id: $entity->id,
			name: $entity->name,
			description: $entity->description,
			goal: $entity->goal,
			avatar: $this->projectAvatarMapper->toResponseArray($entity->avatar),
			ownerId: $entity->ownerId,
			archived: $entity->archived,
			owner: $this->mapOwner($entity->owner),
			chatId: $entity->chatId,
			color: $entity->color,
			members: $members,
			moderatorMembers: $moderatorMembers,
			permissions: $entity->permissions?->toArray(),
			features: $entity->features,
			toggleableFeatures: $entity->toggleableFeatures,
			baseFeatureId: $entity->baseFeatureId,
			privacyType: $entity->privacyType?->value,
			dates: $entity->dates?->toArray(),
			tags: $entity->tags?->getNameList(),
			createdTs: $entity->createdTs,
			activityTs: $entity->activityTs,
			numberOfMembers: $entity->numberOfMembers,
			hasCollabers: $entity->hasCollabers,
			publication: $entity->publication,
			options: $entity->options,
		);
	}

	private function mapOwner(?User $owner): ?array
	{
		if ($owner === null)
		{
			return null;
		}

		return [
			'id' => $owner->id,
			'name' => $owner->name,
			'type' => $owner->type?->value,
			'image' => $owner->image?->toArray(),
		];
	}
}
