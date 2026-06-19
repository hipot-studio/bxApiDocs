<?php

namespace Bitrix\Mobile\Internal\Services\Project;

use Bitrix\Main\Loader;
use Bitrix\Mobile\Internal\Dto\Project\ProjectCreateDto;
use Bitrix\Socialnetwork\Collab\Control\File\File as ProjectAvatarFile;
use Bitrix\Socialnetwork\Helper\Workgroup;

final class ProjectLegacyUpdateService
{
	public function update(int $projectId, ProjectCreateDto $project): int
	{
		if (!Loader::includeModule('rest'))
		{
			throw new \RuntimeException('Rest module is not available');
		}

		$result = \CSocNetLogRestService::updateGroup($this->buildUpdateFields($projectId, $project));
		if ((int)$result <= 0)
		{
			throw new \RuntimeException('Cannot update group');
		}

		Workgroup::setModerators([
			'groupId' => $projectId,
			'userIds' => $this->getModeratorIds($project),
		]);

		return $projectId;
	}

	private function buildUpdateFields(int $projectId, ProjectCreateDto $project): array
	{
		$fields = [
			'GROUP_ID' => $projectId,
			'NAME' => $project->name,
			'DESCRIPTION' => $project->description,
			'INITIATE_PERMS' => $project->initiatePerms,
			'PROJECT_DATE_START' => $project->dateStart,
			'PROJECT_DATE_FINISH' => $project->dateFinish,
			'KEYWORDS' => implode(',', $project->tags),
			'OWNER_ID' => $project->ownerId > 0 ? $project->ownerId : null,
			...$this->mapTypeFields($project->type),
		];

		$avatarFields = $this->mapAvatarFields($project);

		return array_filter(
			array_merge($fields, $avatarFields),
			static fn(mixed $value): bool => $value !== null,
		);
	}

	private function mapTypeFields(string $type): array
	{
		if ($type === 'private')
		{
			return [
				'OPENED' => 'N',
				'VISIBLE' => 'Y',
			];
		}

		return [
			'OPENED' => 'Y',
			'VISIBLE' => 'Y',
		];
	}

	private function mapAvatarFields(ProjectCreateDto $project): array
	{
		$avatar = $project->avatar;
		if (!is_array($avatar))
		{
			return [];
		}

		$avatarId = (int)($avatar['id'] ?? 0);
		if ($avatarId > 0)
		{
			return ['IMAGE_FILE_ID' => $avatarId];
		}

		$encodedFile = $avatar['encodedFile'] ?? null;
		if (!is_string($encodedFile))
		{
			return [];
		}

		if ($encodedFile === '')
		{
			return ['IMAGE_FILE_ID' => false];
		}

		$imageFile = ProjectAvatarFile::createImageFromBase64($encodedFile);
		if ($imageFile === [])
		{
			throw new \RuntimeException('Cannot prepare project avatar');
		}

		return ['IMAGE_ID' => $imageFile];
	}

	private function getModeratorIds(ProjectCreateDto $project): array
	{
		return array_values(array_diff($project->moderatorIds, [$project->ownerId]));
	}
}
