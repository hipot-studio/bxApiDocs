<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserType;
use Bitrix\Main\Loader;

final class OpenLineFolder extends SystemFolder
{
	public const CODE = 'openlines';
	public const RECENT_SECTION = 'openlines';

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getRecentSection(): string
	{
		return self::RECENT_SECTION;
	}

	public function getDefaultPosition(): int
	{
		return 6;
	}

	public function getTitleLangKey(): string
	{
		return 'IM_FOLDER_SYSTEM_OPENLINES';
	}

	public function isAvailable(int $userId): bool
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return false;
		}

		try
		{
			$userType = User::getInstance($userId)->getType();
		}
		catch (\Throwable)
		{
			return false;
		}

		return $userType !== UserType::EXTRANET && $userType !== UserType::COLLABER;
	}
}
