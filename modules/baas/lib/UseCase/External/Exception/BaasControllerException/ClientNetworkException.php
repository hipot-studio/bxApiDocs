<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\External\Exception\BaasControllerException;

use \Bitrix\Main;

class ClientNetworkException extends ClientException
{
	protected $code = 9770;
	public const SYMBOLIC_CODE = 'NETWORK';

	public function getLocalizedMessage(): string
	{
		if (!Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			return Main\Localization\Loc::getMessage('BAAS_EXCEPTION_CLIENT_NETWORK')
				?? 'Network error. Boosts server unreachable. Check the address or net';
		}
		if ($this->error instanceof Main\Error)
		{
			return 'Network connection error: ' . $this->error->getMessage();
		}

		return 'Network connection error: network connection error';
	}

	public function setError(?Main\Error $error): static
	{
		parent::setError($error);

		$this->message = $this->getLocalizedMessage();

		return $this;
	}
}
