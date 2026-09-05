<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserType;
use Bitrix\Main\Loader;

final class OpenLineFolder extends SystemFolder
{
	public const CODE = 'openlines';
	public const REST_CODE = 'lines';
	/** Canonical recent section for open lines is 'lines' (matches REST_CODE), not the stored CODE 'openlines'. */
	public const RECENT_SECTION = 'lines';

	public function getCode(): string
	{
		return self::CODE;
	}

	/** Web expects the wire code 'lines' (REST_CODE); the domain/stored code stays 'openlines' (CODE). */
	public function getRestCode(): string
	{
		return self::REST_CODE;
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
