<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant;

use Bitrix\AiAssistant\Request\Service\Authentication\RequestSignerService;
use Bitrix\Main\Loader;

class Signer
{
	public function sign(int $id): string|null
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return null;
		}

		return (new RequestSignerService)->sign($id);
	}
}
