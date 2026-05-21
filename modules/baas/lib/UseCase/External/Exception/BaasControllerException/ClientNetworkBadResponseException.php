<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\External\Exception\BaasControllerException;

use Bitrix\Main;

class ClientNetworkBadResponseException extends ClientException
{
	protected $code = 9772;
	public const SYMBOLIC_CODE = 'WRONG_SERVER_RESPONSE';

	public function getLocalizedMessage(): string
	{
		if (!Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			return 'Network error. Boosts server unreachable. Check the address or net';
		}
		if (!($this->error instanceof Main\Error))
		{
			return 'Network connection error: boosts server returned an unexpected response';
		}
		if (str_contains($this->error->getMessage(), '500'))
		{
			return 'Boosts server returned an unexpected response: ' . $this->error->getMessage();
		}

		return 'Network connection error: ' . $this->error->getMessage();
	}

	public function setError(?Main\Error $error): static
	{
		parent::setError($error);

		$this->message = $this->getLocalizedMessage();

		return $this;
	}
}
