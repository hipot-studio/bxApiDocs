<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Settings;
use Bitrix\Call\Error;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Web\JWT;


/**
 * @internal
 */
abstract class JwtController extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new HttpMethod([HttpMethod::METHOD_POST]),
			new Csrf(false),
			new CloseSession(),
		];
	}

	protected function decodeJsonParameter(): \Closure
	{
		return function ($className, $params = [])
		{
			$sourceParams = $this->getSourceParametersList();
			$parameters = !empty($sourceParams) ? $sourceParams[0] : [];

			if ($parameters instanceof \Bitrix\Main\Type\Dictionary)
			{
				$parameters = $parameters->getValues();
			}

			if (empty($parameters))
			{
				$jsonBody = file_get_contents('php://input');
				if ($jsonBody)
				{
					$data = \Bitrix\Main\Web\Json::decode($jsonBody);
					if (is_array($data) && !empty($data))
					{
						$parameters = $data;
					}
				}
			}
			return new $className($parameters);
		};
	}

	protected function decodeJwtParameter(): \Closure
	{
		return function ($className, string $jwt = '')
		{
			if ($jwt === '')
			{
				// Parse jwt out of the raw body when Content-Type is JSON
				$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
				if (str_contains($contentType, 'application/json'))
				{
					try
					{
						$body = (string)file_get_contents('php://input');
						$decoded = $body !== '' ? \Bitrix\Main\Web\Json::decode($body) : null;
						if (is_array($decoded) && isset($decoded['jwt']) && is_string($decoded['jwt']))
						{
							$jwt = $decoded['jwt'];
						}
					}
					catch (\Throwable $e) {}
				}
			}

			try
			{
				$payload = JWT::decode($jwt, base64_decode(Settings::getPrivateKey()), ['HS256']);

				return new $className($payload);
			}
			catch (\Exception $e)
			{
				$this->addError(new Error(Error::WRONG_JWT));

				return null;
			}
		};
	}
}