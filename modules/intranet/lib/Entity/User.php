<?php

namespace Bitrix\Intranet\Entity;

use Bitrix\Main\ModuleManager;

class User
{
	public function __construct(
		private ?int $id = null,
		private ?array $departmetnsIds = null,
		private ?string $login = null,
		private ?string $email = null,
		private ?string $name = null,
		private ?string $lastName = null,
		private ?string $confirmCode = null,
		private ?array $groupIds = null,
		private ?string $phoneNamber = null,
		private ?string $xmlId = null,
		private ?bool $active = null,
		private ?string $externalAuthId = null,
	)
	{}

	public static function initByArray(array $userData): self
	{
		$departmetnsIds = null;
		$departments = $userData['UF_DEPARTMENT'] ?? null;
		if (is_array($departments))
		{
			$departmetnsIds = $departments;
		}
		elseif ((int)$departments > 0)
		{
			$departmetnsIds = [(int)$departments];
		}

		return new \Bitrix\Intranet\Entity\User(
			$userData['ID'] ?? null,
				$departmetnsIds,
			$userData['LOGIN'] ?? null,
			$userData['EMAIL'] ?? null,
			$userData['NAME'] ?? null,
			$userData['LAST_NAME'] ?? null,
			$userData["CONFIRM_CODE"] ?? null,
			$userData['GROUP_ID'] ?? null,
			$userData['PHONE_NUMBER'] ?? null,
			$userData['XML_ID'] ?? null,
			$userData['ACTIVE'] ?? null,
			$userData['EXTERNAL_AUTH_ID'] ?? null,
		);
	}

	public function getExternalAuthId(): ?string
	{
		return $this->externalAuthId;
	}

	public function setExternalAuthId(?string $externalAuthId): void
	{
		$this->externalAuthId = $externalAuthId;
	}

	public function getActive(): ?bool
	{
		return $this->active;
	}

	public function setActive(?bool $active): void
	{
		$this->active = $active;
	}

	public function getXmlId(): ?string
	{
		return $this->xmlId;
	}

	public function setXmlId(?string $xmlId): void
	{
		$this->xmlId = $xmlId;
	}

	public function getPhoneNamber(): ?string
	{
		return $this->phoneNamber;
	}

	public function setPhoneNamber(?string $phoneNamber): void
	{
		$this->phoneNamber = $phoneNamber;
	}

	public function getGroupIds(): ?array
	{
		return $this->groupIds;
	}

	public function setGroupIds(?array $groupIds): void
	{
		$this->groupIds = $groupIds;
	}

	public function getConfirmCode(): ?string
	{
		return $this->confirmCode;
	}

	public function setConfirmCode(?string $confirmCode): void
	{
		$this->confirmCode = $confirmCode;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): void
	{
		$this->id = $id;
	}

	public function getDepartmetnsIds(): ?array
	{
		return $this->departmetnsIds;
	}

	public function setDepartmetnsIds(?array $departmetnsIds): void
	{
		$this->departmetnsIds = $departmetnsIds;
	}

	public function getLogin(): ?string
	{
		return $this->login;
	}

	public function setLogin(?string $login): void
	{
		$this->login = $login;
	}

	public function getEmail(): ?string
	{
		return $this->email;
	}

	public function setEmail(?string $email): void
	{
		$this->email = $email;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): void
	{
		$this->name = $name;
	}

	public function getLastName(): ?string
	{
		return $this->lastName;
	}

	public function setLastName(?string $lastName): void
	{
		$this->lastName = $lastName;
	}

	public function isExtranet(): bool
	{
		return (
			ModuleManager::isModuleInstalled('extranet')
			&& (
				empty($this->departmetnsIds)
				|| (
					is_array($this->departmetnsIds)
					&& (int)$this->departmetnsIds[0] <= 0
				)
			)
		);
	}

	public function isIntranet(): bool
	{
		return $this->getDepartmetnsIds()
			&& (
				(
					!empty($this->getDepartmetnsIds())
					&& (int)$this->getDepartmetnsIds()[0] > 0
				)
			);
	}
}