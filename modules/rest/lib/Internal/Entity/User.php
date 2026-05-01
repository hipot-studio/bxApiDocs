<?php

namespace Bitrix\Rest\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

class User implements EntityInterface, Arrayable
{
	public function __construct(
		private ?int $id = null,
		private ?bool $active = null,
		private ?string $login = null,
		private ?string $email = null,
		private ?string $name = null,
		private ?string $lastName = null,
		private ?string $password = null,
		private ?string $timeZone = null,
		private ?string $languageId = null,
		private ?array $groupIds = null,
		private ?string $adminNotes = null,
		private ?string $externalAuthId = null,
	)
	{
	}

	public function getId(): mixed
	{
		return $this->id;
	}

	public function setId(?int $id): User
	{
		$this->id = $id;
		return $this;
	}

	public function isActive(): ?bool
	{
		return $this->active;
	}

	public function setIsActive(?bool $active): User
	{
		$this->active = $active;
		return $this;
	}

	public function getLogin(): ?string
	{
		return $this->login;
	}

	public function setLogin(?string $login): User
	{
		$this->login = $login;
		return $this;
	}

	public function getEmail(): ?string
	{
		return $this->email;
	}

	public function setEmail(?string $email): User
	{
		$this->email = $email;
		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): User
	{
		$this->name = $name;
		return $this;
	}

	public function getLastName(): ?string
	{
		return $this->lastName;
	}

	public function setLastName(?string $lastName): User
	{
		$this->lastName = $lastName;
		return $this;
	}

	public function getPassword(): ?string
	{
		return $this->password;
	}

	public function setPassword(?string $password): User
	{
		$this->password = $password;
		return $this;
	}

	public function getTimeZone(): ?string
	{
		return $this->timeZone;
	}

	public function setTimeZone(?string $timeZone): User
	{
		$this->timeZone = $timeZone;
		return $this;
	}

	public function getLanguageId(): ?string
	{
		return $this->languageId;
	}

	public function setLanguageId(?string $languageId): User
	{
		$this->languageId = $languageId;
		return $this;
	}

	public function getGroupIds(): ?array
	{
		return $this->groupIds;
	}

	public function setGroupIds(?array $groupIds): User
	{
		$this->groupIds = $groupIds;
		return $this;
	}

	public function getAdminNotes(): ?string
	{
		return $this->adminNotes;
	}

	public function setAdminNotes(?string $adminNotes): User
	{
		$this->adminNotes = $adminNotes;
		return $this;
	}

	public function getExternalAuthId(): ?string
	{
		return $this->externalAuthId;
	}

	public function setExternalAuthId(?string $externalAuthId): User
	{
		$this->externalAuthId = $externalAuthId;
		return $this;
	}

	public function toArray(): array
	{
		$data = [
			'ID' => $this->id,
			'LOGIN' => $this->login,
			'EMAIL' => $this->email,
			'NAME' => $this->name,
			'LAST_NAME' => $this->lastName,
			'PASSWORD' => $this->password,
			'TIME_ZONE' => $this->timeZone,
			'LANGUAGE_ID' => $this->languageId,
			'GROUP_ID' => $this->groupIds,
			'ADMIN_NOTES' => $this->adminNotes,
			'EXTERNAL_AUTH_ID' => $this->externalAuthId,
		];

		if ($this->active !== null)
		{
			$data['ACTIVE'] = $this->active ? 'Y' : 'N';
		}

		return array_filter($data, static fn($value) => $value !== null);
	}
}