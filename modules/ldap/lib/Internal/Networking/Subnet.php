<?php

namespace Bitrix\Ldap\Internal\Networking;

/**
 * @package Bitrix\Ldap\Internal
 * You must not use classes from Internal namespace outside current module.
 */
final class Subnet
{
	/** @var string[] */
	private array $ranges = [];

	public static function forAuthentication(): ?Subnet
	{
		$ranges = \COption::GetOptionString('ldap', 'bitrixvm_auth_net', '');

		$subnet = new Subnet((string)$ranges);

		return $subnet->isEmpty() ? null : $subnet;
	}

	public function __construct(string $value)
	{
		$ranges = explode(';', trim($value));
		foreach ($ranges as $range)
		{
			$range = trim($range);

			if (!$this->isValidRange($range))
			{
				continue;
			}

			[ $net, $mask ] = explode('/', $range);

			// xxx.xxx.xxx.xxx/xx -> xxx.xxx.xxx.xxx/xxx.xxx.xxx.xxx
			if (mb_strpos($mask, '.') === false)
			{
				$mask = long2ip('11111111111111111111111111111111' << (32 - (int)$mask));
			}

			$this->ranges[] = "$net/$mask";
		}
	}

	public function includes(Ip|string $ip): bool
	{
		$ip = ($ip instanceof Ip) ? $ip : new Ip($ip);

		if (!$ip->isValid())
		{
			return false;
		}

		foreach ($this->ranges as $range)
		{
			[ $net, $mask ] = explode('/', $range);

			$expectedNet = long2ip(
				ip2long((string)$ip) & ip2long($mask)
			);

			if ($expectedNet === $net)
			{
				return true;
			}
		}

		return false;
	}

	public function isEmpty(): bool
	{
		return empty($this->ranges);
	}

	private function isValidRange(string $range): bool
	{
		if ($range === '')
		{
			return false;
		}

		return preg_match("#^(\d{1,3}\.){3,3}(\d{1,3})/(\d{1,3}\.){3,3}(\d{1,3})$#", $range)
			|| preg_match("#^(\d{1,3}\.){3,3}(\d{1,3})/(\d{1,3})$#", $range);
	}
}
