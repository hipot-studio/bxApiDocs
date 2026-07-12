<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Site;

use Bitrix\Socialnetwork\Item\Workgroup\Type;

class GroupUrl
{
	public static function get(int $groupId, null|string|Type $groupType = null, array $parameters = []): string
	{
		$site = Site::getInstance();

		return $site->getDirectory() . 'workgroups/group/' . $groupId . '/';
	}
}
