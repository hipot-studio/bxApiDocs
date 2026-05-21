<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\External\Exception\BaasControllerException;

use Bitrix\Main;

class ClientNetworkEmptyResponseException extends ClientException
{
	protected $code = 9771;
	public const SYMBOLIC_CODE = 'EMPTY_SERVER_RESPONSE';

	public function getLocalizedMessage(): string
	{
		if (Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			return 'Network connection error: empty response from the boosts server. Check proxy, firewall, or network connection.';
		}

		return 'Network error. Boosts server unreachable. Check the address or net';
	}
}
