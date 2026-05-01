<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access;

use \CUser;

class UserContext
{
	protected ?array $data;
	protected CUser $user;
	private const USER_ACCESS_CODE_AUTHORIZED = 'AU'; // due to \Bitrix\Main\UI\EntitySelector\Converter::convertToFinderCodes
	private const USER_ACCESS_CODE_ALL = 'UA'; //  due to \Bitrix\Main\UI\EntitySelector\Converter::convertToFinderCodes

	public function __construct(protected ?int $userId = null)
	{

	}

	public function getId(): ?int
	{
		return $this->userId;
	}

	public function isAdmin(): bool
	{
		global $USER;
		$checkUserId = $this->userId;
		if ($this->userId > 0 && $USER instanceof \CUser && (int)$this->userId === (int)$USER->getId())
		{
			$checkUserId = 0; // default actions inside isAdmin check for current user
		}

		return \CRestUtil::isAdmin($checkUserId);
	}

	public function canAccess(array $accessCodes): bool
	{
		if (in_array(self::USER_ACCESS_CODE_ALL, $accessCodes))
		{
			$accessCodes[] = self::USER_ACCESS_CODE_AUTHORIZED;
		}

		return $this->getUser()->CanAccess($accessCodes);
	}

	public function getData(): ?array
	{
		if (!isset($this->data))
		{
			if ($data = \CUser::GetByID($this->userId)->fetch())
			{
				$this->data = $data;
			}
			else
			{
				$this->data = null;
			}
		}

		return $this->data;
	}

	private function getUser(): CUser
	{
		if (!isset($this->user))
		{
			global $USER;

			if ($this->userId > 0 && $USER instanceof \CUser && (int)$this->userId === (int)$USER->getId())
			{
				$this->user = $USER;
			}
			else
			{
				$this->user = new class((int)$this->userId) extends CUser {
					public function __construct(protected int $targetUserId)
					{
						parent::__construct();
					}

					public function GetID()
					{
						return $this->targetUserId;
					}

					public function IsAuthorized(): bool
					{
						return false;
					}
				};
			}
		}

		return $this->user;
	}
}
