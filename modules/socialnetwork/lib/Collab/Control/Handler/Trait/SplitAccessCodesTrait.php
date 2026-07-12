<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Trait;

use Bitrix\Main\Access\AccessCode;

trait SplitAccessCodesTrait
{
	/**
	 * @param string[] $accessCodes
	 * @return array{string[], string[]} [userCodes, departmentCodes]
	 */
	private function splitAccessCodes(array $accessCodes): array
	{
		$userCodes = [];
		$departmentCodes = [];
		foreach ($accessCodes as $code)
		{
			if ((new AccessCode($code))->getEntityType() === AccessCode::TYPE_DEPARTMENT)
			{
				$departmentCodes[] = $code;
			}
			else
			{
				$userCodes[] = $code;
			}
		}

		return [$userCodes, $departmentCodes];
	}
}
