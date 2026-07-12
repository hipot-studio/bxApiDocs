<?php

declare(strict_types=1);

namespace Bitrix\SocialNetwork\Collab\Access\Rule\Trait;

use Bitrix\Main\Access\AccessCode;

trait UserAccessCodeTrait
{
	protected function extractUserIdFromAccessCode(string $accessCode): ?int
	{
		$parsedAccessCode = new AccessCode($accessCode);
		if ($parsedAccessCode->getEntityType() !== AccessCode::TYPE_USER)
		{
			return null;
		}

		$userId = $parsedAccessCode->getEntityId();

		return $userId > 0 ? $userId : null;
	}
}
