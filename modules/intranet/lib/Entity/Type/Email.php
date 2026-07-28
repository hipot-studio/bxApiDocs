<?php

namespace Bitrix\Intranet\Entity\Type;

class Email
{
	public function __construct(
		private readonly string $email,
	)
	{}

	public function toLogin(): string
	{
		return $this->email;
	}

	public function getValue(): string
	{
		return mb_strtolower(trim($this->email));
	}

	public function isValid(): bool
	{
		return check_email($this->email);
	}

	public function getMaskedEmail(): string
	{
		$emailParts = explode('@', $this->getValue());
		$domainParts = explode('.', $emailParts[1]);

		return mb_substr($emailParts[0], 0, 1)
			. '***'
			. (mb_strlen($emailParts[0]) > 3 ? mb_substr($emailParts[0], -1) : '')
			. '@***'
			. (count($domainParts) > 1 ? '.' . end($domainParts) : mb_substr($emailParts[1], -2));
	}
}