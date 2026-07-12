<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Internals\Group\GroupEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Avatar;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\PermissionCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectDates;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectGridRow;
use Bitrix\Socialnetwork\V2\Internal\Entity\PrivacyType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectTagCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\User;
use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;

class ProjectMapper
{
	/**
	 * @param array<string, mixed> $groupRow
	 * @param string[]|null $tags
	 */
	public function mapFromOrmRow(
		array $groupRow,
		?array $tags = null,
		?PermissionCollection $permissions = null,
		?MemberEntityCollection $members = null,
		?MemberEntityCollection $moderatorMembers = null,
		?bool $hasCollabers = null,
		?File $image = null,
		?array $options = null,
	): Project
	{
		$privacyType = $this->mapPrivacyType(
			$groupRow['VISIBLE'] ?? null,
			$groupRow['OPENED'] ?? null,
		);

		$projectDates = $this->mapDates(
			$groupRow['PROJECT_DATE_START'] ?? null,
			$groupRow['PROJECT_DATE_FINISH'] ?? null,
		);

		$archived = $this->mapArchived($groupRow['CLOSED'] ?? null);
		$avatar = $this->mapAvatar($groupRow['IMAGE_ID'] ?? null, $image);

		return new Project(
			id: isset($groupRow['ID']) ? (int)$groupRow['ID'] : null,
			name: is_string($groupRow['NAME'] ?? null) ? $groupRow['NAME'] : null,
			description: is_string($groupRow['DESCRIPTION'] ?? null) ? $groupRow['DESCRIPTION'] : null,
			goal: is_string($groupRow['GOAL'] ?? null) ? $groupRow['GOAL'] : null,
			avatar: $avatar,
			ownerId: isset($groupRow['OWNER_ID']) ? (int)$groupRow['OWNER_ID'] : null,
			archived: $archived,
			permissions: $permissions,
			privacyType: $privacyType,
			dates: $projectDates,
			tags: $this->mapTags($tags),
			createdTs: $this->toTimestamp($groupRow['DATE_CREATE'] ?? null),
			activityTs: $this->toTimestamp($groupRow['DATE_ACTIVITY'] ?? null),
			numberOfMembers: $this->toInt($groupRow['NUMBER_OF_MEMBERS'] ?? null),
			members: $members,
			moderatorMembers: $moderatorMembers,
			options: $options,
			publication: isset($groupRow['LANDING']) ? $groupRow['LANDING'] === 'Y' : null,
			hasCollabers: $hasCollabers,
		);
	}

	/**
	 * @param string[]|null $tags
	 */
	public function mapFromOrm(
		GroupEntity $group,
		?array $tags = null,
		?PermissionCollection $permissions = null,
		?Avatar $avatar = null,
		?User $owner = null,
		?int $numberOfModerators = null,
		?bool $hasCollabers = null,
		?array $options = null,
	): Project
	{
		$privacyType = $this->mapPrivacyType(
			$group->getVisible(),
			$group->getOpened(),
		);

		$projectDates = $this->mapDates(
			$group->getProjectDateStart(),
			$group->getProjectDateFinish(),
		);

		$archived = $this->mapArchived($group->getClosed());

		return new Project(
			id: $group->getId(),
			name: $group->getName(),
			description: $group->getDescription(),
			goal: $group->getGoal(),
			avatar: $avatar,
			owner: $owner,
			ownerId: $group->getOwnerId(),
			archived: $archived,
			permissions: $permissions,
			privacyType: $privacyType,
			dates: $projectDates,
			tags: $this->mapTags($tags),
			createdTs: $this->toTimestamp($group->getDateCreate()),
			activityTs: $this->toTimestamp($group->getDateActivity()),
			numberOfMembers: $group->getNumberOfMembers(),
			numberOfModerators: $numberOfModerators,
			options: $options,
			publication: $group->getLanding(),
			hasCollabers: $hasCollabers,
		);
	}

	public function mapToGridRow(
		Project $project,
		?UserCollection $members = null,
		array $tags = [],
		?string $activityDate = null,
		?string $dateRelation = null,
		?string $dateView = null,
		?int $scrumMasterId = null,
		?string $type = null,
		?string $privacyType = null,
	): ProjectGridRow
	{
		return new ProjectGridRow(
			id: $project->id,
			name: $project->name,
			avatar: $project->avatar,
			owner: $project->owner,
			dateCreate: $project->createdTs !== null
				? DateTime::createFromTimestamp($project->createdTs)->toString()
				: null,
			dateActivity: $project->activityTs !== null
				? DateTime::createFromTimestamp($project->activityTs)->toString()
				: null,
			activityDate: $activityDate,
			privacyType: $privacyType ?? $project->privacyType?->value,
			efficiency: $project->efficiency !== null ? (string)$project->efficiency : null,
			members: $members,
			numberOfMembers: $project->numberOfMembers,
			numberOfModerators: $project->numberOfModerators,
			closed: $project->archived,
			hasCollabers: $project->hasCollabers,
			tags: !empty($tags) ? $tags : null,
			dateRelation: $dateRelation,
			dateView: $dateView,
			projectDateStart: $project->dates?->start?->toString(),
			projectDateFinish: $project->dates?->finish?->toString(),
			scrumMasterId: $scrumMasterId,
			type: $type,
		);
	}

	private function mapAvatar(mixed $imageId, ?File $image = null): ?Avatar
	{
		$id = $image?->id;
		if ($id === null && is_numeric($imageId) && (int)$imageId > 0)
		{
			$id = (int)$imageId;
		}

		$url = $image?->src;
		if ($url === null || $url === '')
		{
			$url = $this->mapAvatarUrl($imageId);
		}

		if ($id === null && $url === null)
		{
			return null;
		}

		return new Avatar(
			id: ($id !== null && $id > 0) ? $id : null,
			url: $url,
		);
	}

	private function mapDates(mixed $start, mixed $finish): ?ProjectDates
	{
		$startDate = $this->toDateTime($start);
		$finishDate = $this->toDateTime($finish);

		if ($startDate === null && $finishDate === null)
		{
			return null;
		}

		return new ProjectDates(
			start: $startDate,
			finish: $finishDate,
		);
	}

	private function mapAvatarUrl(mixed $imageId): ?string
	{
		$id = is_numeric($imageId) ? (int)$imageId : 0;
		if ($id <= 0)
		{
			return null;
		}

		$path = \CFile::GetPath($id);

		return is_string($path) && $path !== '' ? $path : null;
	}

	private function mapPrivacyType(mixed $visible, mixed $opened): ?PrivacyType
	{
		if (!$this->isLegacyFlagLoaded($visible) || !$this->isLegacyFlagLoaded($opened))
		{
			return null;
		}

		return PrivacyType::fromLegacyFlags($visible, $opened);
	}

	/**
	 * @param string[]|null $tags
	 */
	private function mapTags(?array $tags): ?ProjectTagCollection
	{
		if ($tags === null)
		{
			return null;
		}

		return ProjectTagCollection::mapFromArray(
			array_map(
				static fn(string $tag): array => ['name' => $tag],
				array_values(
					array_unique(
						array_filter(
							$tags,
							static fn(string $tag): bool => $tag !== ''
						)
					)
				)
			)
		);
	}

	private function toDateTime(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		return null;
	}

	private function toTimestamp(mixed $value): ?int
	{
		if ($value instanceof DateTime)
		{
			return $value->getTimestamp();
		}

		return null;
	}

	private function toInt(mixed $value): ?int
	{
		return is_numeric($value) ? (int)$value : null;
	}

	private function isLegacyFlagLoaded(mixed $value): bool
	{
		return is_bool($value) || is_string($value);
	}

	private function mapArchived(mixed $value): ?bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		if (is_string($value))
		{
			return $value === 'Y';
		}

		return null;
	}
}
