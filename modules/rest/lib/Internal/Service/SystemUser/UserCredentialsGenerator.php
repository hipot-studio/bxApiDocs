<?php

namespace Bitrix\Rest\Internal\Service\SystemUser;

use Bitrix\Main\Security\Random;
use CUser;
class UserCredentialsGenerator
{
	public function generatePasswordByGroupsIds(array $groupIds): string
	{
		return CUser::GeneratePasswordByPolicy($groupIds);
	}

	public function generateLogin(): string
	{
		return Random::getString(20) . '.bitrix.rest';
	}

	public function generateEmail(): string
	{
		return Random::getString(30) . '@bitrix.rest';
	}
}