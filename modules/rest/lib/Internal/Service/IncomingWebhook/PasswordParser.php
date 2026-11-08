<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\IncomingWebhook;

use Bitrix\Main;

class PasswordParser
{
	public function parse(string $webhook): ?string
	{
		if (!str_starts_with($webhook, 'http://')
			&& !str_starts_with($webhook, 'https://')
		)
		{
			return $webhook;
		}

		$seekFor = '__password__';
		$pattern = explode(
			'/',
			(new Main\Web\Uri(\CRestUtil::getWebhookEndpoint($seekFor, 'needless')))
				->getPath(),
		);

		$foundKey = array_search($seekFor, $pattern);
		if ($foundKey === false)
		{
			return null;
		}

		$webhookPath = (new Main\Web\Uri($webhook))->getPath();
		$restEndpointPath = (new Main\Web\Uri(\CRestUtil::getEndpoint()))->getPath();
		if (str_starts_with($webhookPath, $restEndpointPath . 'api/'))
		{
			$foundKey++;
		}

		$analyzed = explode('/', $webhookPath);

		return $analyzed[$foundKey] ?? null;
	}
}
