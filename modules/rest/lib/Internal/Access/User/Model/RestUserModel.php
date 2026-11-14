<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\User\Model;

use Bitrix\Main\Access\User\UserModel;
use CUser;

class RestUserModel extends UserModel
{
	private const USER_ACCESS_CODE_AUTHORIZED = 'AU';
	private const USER_ACCESS_CODE_ALL = 'UA';

	private ?array $data = null;
	private bool $isDataLoaded = false;
	private ?CUser $legacyUser = null;

	public function getRoles(): array
	{
		return [];
	}

	public function getPermission(string $permissionId): ?int
	{
		return null;
	}

	public function isAdmin(): bool
	{
		if ($this->isAdmin === null)
		{
			global $USER;

			$checkUserId = $this->getUserId();
			if ($checkUserId <= 0)
			{
				$this->isAdmin = false;

				return $this->isAdmin;
			}

			if ($USER instanceof CUser && $checkUserId === (int)$USER->getId())
			{
				$checkUserId = 0;
			}

			$this->isAdmin = \CRestUtil::isAdmin($checkUserId);
		}

		return $this->isAdmin;
	}

	public function getId(): int
	{
		return $this->getUserId();
	}

	public function canAccess(array $accessCodes): bool
	{
		if (in_array(self::USER_ACCESS_CODE_ALL, $accessCodes, true))
		{
			$accessCodes[] = self::USER_ACCESS_CODE_AUTHORIZED;
		}

		return $this->getLegacyUser()->CanAccess($accessCodes);
	}

	public function getAccessCodes(): array
	{
		$userId = $this->getUserId();
		if ($userId <= 0)
		{
			return ['G2'];
		}

		$codes = \CAccess::GetUserCodesArray($userId);
		$codes[] = self::USER_ACCESS_CODE_AUTHORIZED;
		$codes[] = self::USER_ACCESS_CODE_ALL;

		return array_values(array_unique($codes));
	}

	public function getData(): ?array
	{
		if (!$this->isDataLoaded)
		{
			$this->data = CUser::GetByID($this->getUserId())->fetch() ?: null;
			$this->isDataLoaded = true;
		}

		return $this->data;
	}

	private function getLegacyUser(): CUser
	{
		if ($this->legacyUser === null)
		{
			global $USER;

			if ($this->getUserId() > 0 && $USER instanceof CUser && (int)$this->getUserId() === (int)$USER->getId())
			{
				$this->legacyUser = $USER;
			}
			else
			{
				$this->legacyUser = new class((int)$this->getUserId()) extends CUser {
					public function __construct(private readonly int $targetUserId)
					{
						parent::__construct();
					}

					public function GetID()
					{
						return $this->targetUserId;
					}

					public function IsAuthorized(): bool
					{
						return $this->targetUserId > 0;
					}
				};
			}
		}

		return $this->legacyUser;
	}
}
