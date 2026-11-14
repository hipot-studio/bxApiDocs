<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Error;
use Bitrix\Call\Controller\Filter\JwtAuthentication;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;


/**
 * Every action requires a user unless it is declared a server-to-server one with configureJwtAction().
 *
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
			new Authentication(),
		];
	}

	/**
	 * Configures an action the call server calls without a user session: the request is let in by a token
	 * signed with the portal key instead of a user. The token is checked ahead of the given prefilters.
	 *
	 * @param array $prefilters Prefilters the action carries on top of the defaults.
	 */
	protected function configureJwtAction(array $prefilters = []): array
	{
		return [
			'-prefilters' => [
				Authentication::class,
			],
			'+prefilters' => [
				new JwtAuthentication(),
				...$prefilters,
			],
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
			$reader = new JwtReader();
			if ($jwt === '')
			{
				$jwt = $reader->read($this->getSourceParametersList());
			}

			$payload = $reader->decode($jwt);
			if ($payload !== null)
			{
				try
				{
					return new $className($payload);
				}
				catch (\Exception $e)
				{
					// A payload the DTO refuses is answered the same way as a bad signature
				}
			}

			$this->addError(new Error(Error::WRONG_JWT));

			return null;
		};
	}
}