<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Model;

use Bitrix\Bizproc\Internal\Access\OwnTemplatesOption;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Access\PermissionCache;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Main\Access\User\UserModel as MainUserModel;

class UserModel extends MainUserModel
{
	private ?array $permissions = null;

	/**
	 * Access codes read for a whole set of users at once, in place of a query per user. Lives for the span of
	 * the call that filled it and no longer {@see self::preloadAccessCodes()}.
	 *
	 * @var array<int, string[]> user id => access codes
	 */
	private static array $preloadedAccessCodes = [];

	/**
	 * Hands the models of a set the codes of that whole set, so its checks stop paying a query per user. Not a
	 * cache: the map is dropped by the caller that filled it {@see self::forgetPreloadedAccessCodes()} as soon
	 * as the set is answered, because codes outliving one call would start answering with yesterday's
	 * membership in a long-running process (an agent, a console script, a queue). The optimization is kept
	 * whole by that: a model that read its codes off the map keeps them memoized on itself, so the drop costs
	 * the checks that follow nothing.
	 *
	 * @param array<int, string[]> $accessCodesByUser
	 */
	public static function preloadAccessCodes(array $accessCodesByUser): void
	{
		self::$preloadedAccessCodes = $accessCodesByUser;
	}

	public static function forgetPreloadedAccessCodes(): void
	{
		self::$preloadedAccessCodes = [];
	}

	/**
	 * The single place a user's own codes are read, so the preloaded set replaces the query and nothing else:
	 * the employee code and the reading of a converted company structure stay where the framework put them, one
	 * level above ({@see MainUserModel::getAccessCodes()}).
	 */
	protected function buildAccessCodes(): array
	{
		return self::$preloadedAccessCodes[$this->userId] ?? parent::buildAccessCodes();
	}

	public function getRoles(): array
	{
		if ($this->roles === null)
		{
			$this->roles = [];
			if ($this->userId === 0 || empty($this->getAccessCodes()))
			{
				return $this->roles;
			}

			$this->roles = Container::getAccessRepository()->getRoleIdsByAccessCodes($this->getAccessCodes());
		}

		return $this->roles;
	}

	public function getPermission(string $permissionId): ?int
	{
		$permissions = $this->getPermissions();
		$value = $permissions[$permissionId] ?? null;

		if (is_array($value))
		{
			return isset($value[0]) ? (int)$value[0] : null;
		}

		return $value === null ? null : (int)$value;
	}

	/**
	 * Effective scope of a multivariables permission: `[-1]` for admins and roles with the «all» scope
	 * (`-1` dominates), otherwise the union of `VALUE` rows across the user roles. This is the basis of
	 * the delegate scope used by the configure-rights validator (Q-002).
	 *
	 * The "own" sentinel is subtracted here - at the single source of the scope - when the portal switch is off,
	 * so both consumers of the scope answer that state identically and cannot drift apart.
	 */
	public function getPermissionMulti(string $permissionId): ?array
	{
		if ($this->isAdmin())
		{
			return [PermissionDictionary::VALUE_VARIATION_ALL];
		}

		$permissions = $this->getPermissions();
		$value = $permissions[$permissionId] ?? null;

		if (!is_array($value))
		{
			return null;
		}

		if (OwnTemplatesOption::isEnabled())
		{
			return $value;
		}

		return array_values(
			array_filter(
				$value,
				static fn($scopeValue): bool => (int)$scopeValue !== PermissionDictionary::VALUE_VARIATION_OWN,
			)
		);
	}

	/**
	 * Effective permission layout, read through the tagged {@see PermissionCache}. The heavy role/permission
	 * merge and its `-1` domination live in the cache; the per-hit static memo here just avoids re-entering it.
	 */
	private function getPermissions(): array
	{
		$this->permissions ??= PermissionCache::getInstance()->getEffective($this->userId, $this->getAccessCodes());

		return $this->permissions;
	}
}
