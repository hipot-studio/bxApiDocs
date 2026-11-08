<?php

declare(strict_types=1);

namespace Bitrix\Lists\Integration\Socialnetwork;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectOptionProvider;
use Bitrix\Socialnetwork\WorkgroupTable;

final class ProjectHeaderProvider
{
	public function get(int $projectId, int $userId): ?array
	{
		if (
			$projectId <= 0
			|| !Loader::includeModule('socialnetwork')
			|| !class_exists(ProjectOptionProvider::class)
			|| !method_exists(ProjectOptionProvider::class, 'isCollabConverted')
			|| !\CSocNetFeaturesPerms::CanPerformOperation(
				$userId,
				SONET_ENTITY_GROUP,
				$projectId,
				'group_lists',
				'view',
				\CSocNetUser::IsCurrentUserModuleAdmin()
			)
		)
		{
			return null;
		}

		$workgroup = WorkgroupTable::getByPrimary($projectId, [
			'select' => ['NAME', 'IMAGE_ID', 'TYPE', 'SCRUM_MASTER_ID'],
		])->fetch();
		if (
			!$workgroup
			|| $workgroup['TYPE'] === Type::Scrum->value
			|| (int)$workgroup['SCRUM_MASTER_ID'] > 0
		)
		{
			return null;
		}

		if (!(new ProjectOptionProvider())->isCollabConverted($projectId))
		{
			return null;
		}

		$name = trim((string)$workgroup['NAME']);
		if ($name === '')
		{
			return null;
		}

		$imageId = (int)$workgroup['IMAGE_ID'];

		return [
			'NAME' => $name,
			'IMAGE_URL' => $imageId > 0 ? (string)\CFile::GetPath($imageId) : '',
		];
	}
}
