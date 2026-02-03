<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Application\Config;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Rest\RestConvertible;

class LegacyCurrentUser implements RestConvertible
{
	public static function getRestEntityName(): string
	{
		return 'legacyCurrentUser';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$option['JSON'] = 'Y';
		$user = User::getCurrent();
		$rest = $user->getArray($option);
		$rest['isAdmin'] = $user->isAdmin();
		$rest['profile'] = \CIMContactList::GetUserPath($user->getId());

		return $rest;
	}
}
