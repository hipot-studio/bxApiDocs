<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Security;

use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\TimeSigner;

class HandlerUrlSignature
{
	private const SALT = 'rest.app_handler_diagnostic';
	private const LIFETIME = '+5 minutes';

	public function sign(string $url): string
	{
		if ($url === '')
		{
			return '';
		}

		return $this->getSigner()->sign($url, self::LIFETIME, $this->getSalt());
	}

	public function extract(string $signedUrl): ?string
	{
		if ($signedUrl === '')
		{
			return null;
		}

		try
		{
			$url = $this->getSigner()->unsign($signedUrl, $this->getSalt());
		}
		catch (BadSignatureException)
		{
			return null;
		}

		return is_string($url) && $url !== '' ? $url : null;
	}

	protected function getSigner(): TimeSigner
	{
		return new TimeSigner();
	}

	protected function getSalt(): string
	{
		return self::SALT;
	}
}
