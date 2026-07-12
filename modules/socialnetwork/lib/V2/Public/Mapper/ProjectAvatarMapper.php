<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Mapper;

use Bitrix\Socialnetwork\V2\Internal;
use Bitrix\Socialnetwork\V2\Public;

class ProjectAvatarMapper
{
	public function toEntity(
		?Public\Dto\Project\Avatar $avatar,
	): ?Internal\Entity\Project\Avatar
	{
		if ($avatar === null)
		{
			return null;
		}

		return new Internal\Entity\Project\Avatar(
			id: $avatar->id,
			url: $avatar->url,
			encodedFile: $avatar->encodedFile,
		);
	}

	public function toResponseArray(
		?Internal\Entity\Project\Avatar $avatar,
	): ?array
	{
		if ($avatar === null)
		{
			return null;
		}

		return [
			'id' => $avatar->id,
			'url' => $avatar->url,
			'encodedFile' => null,
		];
	}
}
