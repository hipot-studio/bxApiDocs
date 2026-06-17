<?php

namespace Bitrix\Socialservices\OAuth;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

trait OAuthErrorTrait
{
	protected function sendOauthError(OAuthErrorCode $code): never
	{
		[$statusCode, $statusText, $messageKey] = match ($code) {
			OAuthErrorCode::MissingCode => [400, 'Bad Request', 'SOCSERV_OAUTH_ERROR_MISSING_CODE'],
			OAuthErrorCode::InvalidCheckKey => [400, 'Bad Request', 'SOCSERV_OAUTH_ERROR_INVALID_CHECK_KEY'],
			OAuthErrorCode::Unknown => [500, 'Internal Server Error', 'SOCSERV_OAUTH_ERROR_UNKNOWN'],
		};

		$message = Loc::getMessage($messageKey);
		if (!is_string($message) || $message === '')
		{
			$message = (string)Loc::getMessage('SOCSERV_OAUTH_ERROR_UNKNOWN');
		}
		$app = Application::getInstance();
		$response = $app->getContext()->getResponse();

		$response->setStatus($statusCode . ' ' . $statusText);
		$response->addHeader('Content-Type', 'text/plain; charset=UTF-8');
		$response->setContent($message);

		$app->end(response: $response);

		throw new \RuntimeException('Application end() did not terminate execution.');
	}
}
