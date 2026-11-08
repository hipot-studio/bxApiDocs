<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Handler\Trait;

use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;

trait SplitAccessCodesTrait
{
	/**
	 * @param string[] $accessCodes
	 * @return array{string[], string[]} [userCodes, structureCodes]
	 */
	private function splitAccessCodes(array $accessCodes): array
	{
		$mapper = Container::getInstance()->get(MemberEntityMapper::class);

		$userCodes = [];
		$structureCodes = [];
		foreach ($accessCodes as $code)
		{
			$isStructureCode = match ($mapper->fromAccessCode($code)?->type)
			{
				MemberEntityType::Department, MemberEntityType::Team => true,
				default => false,
			};

			if ($isStructureCode)
			{
				$structureCodes[] = $code;
			}
			else
			{
				$userCodes[] = $code;
			}
		}

		return [$userCodes, $structureCodes];
	}
}
