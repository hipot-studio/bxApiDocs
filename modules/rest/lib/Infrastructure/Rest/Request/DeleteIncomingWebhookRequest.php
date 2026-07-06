<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request;

use Bitrix\Main;
use Bitrix\Rest\V3\Interaction\Request\Request;

class DeleteIncomingWebhookRequest extends Request
{
	public string $id;

	private ?string $parsedWebhookPassword = null;

	public function getWebhookPassword(): ?string
	{
		if ($this->parsedWebhookPassword !== null)
		{
			return $this->parsedWebhookPassword;
		}

		if (!str_starts_with($this->id, 'http://')
			&& !str_starts_with($this->id, 'https://')
		)
		{
			$this->parsedWebhookPassword = $this->id;

			return $this->parsedWebhookPassword;
		}

		$seekFor = '__password__';
		$pattern = explode(
			'/',
			(new Main\Web\Uri(\CRestUtil::getWebhookEndpoint($seekFor, 'needless')))
				->getPath()
		);
		$foundKey = array_search($seekFor, $pattern);
		if ($foundKey === false)
		{
			return null;
		}

		$analyzed = explode('/', (new Main\Web\Uri($this->id))->getPath());
		$this->parsedWebhookPassword = $analyzed[$foundKey] ?? null;

		return $this->parsedWebhookPassword;
	}
}
