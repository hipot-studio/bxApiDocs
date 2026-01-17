<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\User\Widget\Content\Tool;

use Bitrix\Intranet\User;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class PerformanUserProfile extends BaseTool
{
	public static function isAvailable(User $user): bool
	{
		if (!Loader::includeModule('performan'))
		{
			return false;
		}

		$isExtranetSite = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();

		return !$isExtranetSite && $user->isIntranet();
	}

	public function getName(): string
	{
		return 'performanUserProfile';
	}

	public function getConfiguration(): array
	{
		$userId = $this->user->getId();

		return [
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_PERFORMAN_USER_PROFILE_TITLE'),
			'path' => "/performan/profile/{$userId}/",
			'userId' => $userId,
		];
	}
}
