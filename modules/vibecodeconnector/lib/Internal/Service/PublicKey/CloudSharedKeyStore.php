<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Main\Config\Option;

final class CloudSharedKeyStore
{
	private const OPTION_NAME = 'auth_public_key';

	public function get(): string
	{
		return (string)Option::get('vibecodeconnector', self::OPTION_NAME, '');
	}

	public function set(string $pem): void
	{
		Option::set('vibecodeconnector', self::OPTION_NAME, $pem);
	}
}
