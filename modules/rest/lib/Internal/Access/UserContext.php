<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access;

class UserContext
{
	protected ?array $data;

	public function __construct(protected ?int $userId = null)
	{

	}

	public function getId(): int
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
}
