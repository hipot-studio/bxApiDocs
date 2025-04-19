<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Internal\Factory;

use Bitrix\Tasks\Onboarding\Internal\Type;
use Bitrix\Tasks\Onboarding\Transfer\UserJob;

final class UserJobFactory
{
	public static function createUserJob(Type $type, int $userId): UserJob
	{
		return new UserJob($type, $userId);
	}
}