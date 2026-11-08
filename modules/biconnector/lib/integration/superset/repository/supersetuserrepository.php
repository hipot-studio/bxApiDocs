<?php

namespace Bitrix\BIConnector\Integration\Superset\Repository;

use Bitrix\Main\UserTable;
use Bitrix\Main\UserGroupTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Integration\Superset\SupersetUserLogin;

final class SupersetUserRepository
{
	public function getById(int $id): ?User
	{
		$query = UserTable::query()
			->setSelect([
				'ID',
				// the portal login from b_user, not the derived Superset one
				'LOGIN',
				'EMAIL',
				'NAME',
				'LAST_NAME',
				'ACTIVE',
				'SUPERSET_CLIENT_ID' => 'SUPERSET_USER.CLIENT_ID',
				'SUPERSET_PERMISSION_HASH' => 'SUPERSET_USER.PERMISSION_HASH',
				'SUPERSET_UPDATED' => 'SUPERSET_USER.UPDATED',
			])
			->where('ID', '=', $id)
			->where('REAL_USER', 'expr', true)
			->setLimit(1)
			->registerRuntimeField(
				new Reference(
					'SUPERSET_USER',
					SupersetUserTable::class,
					Join::on('this.ID', 'ref.USER_ID'),
					['join_type' => Join::TYPE_LEFT]
				)
			)
			->setCacheTtl(86400)
		;

		$result = $query->exec();
		$user = $result->fetch();
		if ($user)
		{
			$id = (int)$user['ID'];
			$username = self::getUsername($id);
			$email = self::getEmail($username);

			return new User(
				id: $id,
				userName: $username,
				email: $email,
				firstName: $user['NAME'] ?: $user['LOGIN'],
				lastName: $user['LAST_NAME'] ?: $user['LOGIN'],
				active: $user['ACTIVE'] === 'Y',
				clientId: $user['SUPERSET_CLIENT_ID'] ?: null,
				permissionHash: $user['SUPERSET_PERMISSION_HASH'] ?: null,
				updated: $user['SUPERSET_UPDATED'] ? $user['SUPERSET_UPDATED'] === 'Y' : null,
			);
		}

		return null;
	}

	public function getAdmin(): ?User
	{
		static $result = null;
		if ($result)
		{
			return $result;
		}

		$user = UserGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', 1)
			->whereNull('DATE_ACTIVE_TO')
			->where('USER.ACTIVE', 'Y')
			->where('USER.REAL_USER', 'expr', true)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		if ($user)
		{
			$result = $this->getById((int)$user['USER_ID']);

			return $result;
		}

		return null;
	}

	/**
	 * Resolves the portal user behind a Superset account login.
	 *
	 * Establishes the identity and nothing else: neither ACTIVE nor REAL_USER is filtered here, so
	 * a dismissed employee stays resolvable and the caller decides about access. The lookup is not
	 * cached - it has to see an account created a moment ago.
	 */
	public function getUserIdByLogin(string $login): ?int
	{
		if ($login === '')
		{
			return null;
		}

		$row = SupersetUserTable::query()
			->setSelect(['USER_ID'])
			->where('LOGIN', $login)
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['USER_ID'] : null;
	}

	private static function getUsername(int $id): string
	{
		return SupersetUserLogin::getLogin($id);
	}

	private static function getEmail(string $username): string
	{
		return SupersetUserLogin::getEmail($username);
	}
}
