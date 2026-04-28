<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\User;

use Bitrix\Im\Model\UserTable;
use Bitrix\Im\V2\Integration\Extranet\CollaberService;

final class UserClassResolver
{
	/** @return class-string<User> */
	public function resolve(array $data): string
	{
		return match (true)
		{
			$data['IS_BOT'] ?? false => UserBot::class,
			CollaberService::getInstance()->isCollaber((int)$data['ID']) => UserCollaber::class,
			$this->isGuest($data) => UserGuest::class,
			$data['IS_EXTRANET'] ?? false => UserExtranet::class,
			$this->isExternal($data) => UserExternal::class,
			default => User::class,
		};
	}

	private function isExternal(array $params): bool
	{
		$externalTypes = UserTable::filterExternalUserTypes(['bot']);

		return in_array($params['EXTERNAL_AUTH_ID'], $externalTypes, true);
	}

	private function isGuest(array $params): bool
	{
		return ($params['EXTERNAL_AUTH_ID'] ?? '') === UserGuest::AUTH_ID;
	}
}
