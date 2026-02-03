<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\MailboxConnect;

use Bitrix\Main\HttpRequest;

final class MailboxConnectDTO extends AbstractMailboxConnectDTO
{
	public static function createFromRequest(HttpRequest $request): self
	{
		$fromInt = static fn(?int $value) => $value ? 'Y' : 'N';
		$email = $request->get('email');

		if (is_null($email))
		{
			$email = $request->get('login') ?: $request->get('loginWithoutDomain');
			$login = (string)$request->get('loginWithoutDomain');
		}
		else
		{
			$login = (string)$request->get('login');
		}

		$constructorData = [
			'email' => (string)$email,
			'login' => $login,
			'password' => (string)$request->get('password'),
			'serviceId' => (int)$request->get('serviceId'),
			'server' => (string)$request->get('server'),
			'port' => (string)$request->get('port'),
			'ssl' => $fromInt((int)$request->get('ssl')),
			'storageOauthUid' => (string)$request->get('storageOauthUid'),
			'syncAfterConnection' => $fromInt((int)$request->get('syncAfterConnection')),
			'useSmtp' => $fromInt((int)$request->get('useSmtp')),
			'serverSmtp' => (string)$request->get('serverSmtp'),
			'portSmtp' => (string)$request->get('portSmtp'),
			'sslSmtp' => $fromInt((int)$request->get('sslSmtp')),
			'loginSmtp' => (string)$request->get('loginSmtp'),
			'passwordSMTP' => (string)$request->get('passwordSMTP'),
		];

		$constructorData = array_filter($constructorData);

		return new self(...$constructorData);
	}
}
