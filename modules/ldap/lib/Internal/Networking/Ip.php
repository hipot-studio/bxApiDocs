<?php

namespace Bitrix\Ldap\Internal\Networking;

/**
 * @package Bitrix\Ldap\Internal
 * You must not use classes from Internal namespace outside current module.
 */
final class Ip
{
	private string $value;

	public function __construct(string $value)
	{
		$this->value = trim($value);
	}

	public static function current(): Ip
	{
		return new Ip($_SERVER['REMOTE_ADDR']);
	}

	public function belongsTo(Subnet $subnet): bool
	{
		return $subnet->includes($this);
	}

	public function outsideOf(Subnet $subnet): bool
	{
		return !$this->belongsTo($subnet);
	}

	public function isValid(): bool
	{
		return (bool)preg_match("#^(\d{1,3}\.){3,3}(\d{1,3})$#", $this->value);
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
