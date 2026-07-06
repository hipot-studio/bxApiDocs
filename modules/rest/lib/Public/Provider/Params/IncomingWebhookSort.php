<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Params;

use Bitrix\Main\Provider\Params\Sort;

final class IncomingWebhookSort extends Sort
{
	/**
	 * @return string[]
	 */
	protected function getAllowedFields(): array
	{
		return ['ID', 'TITLE', 'DATE_CREATE', 'DATE_LOGIN'];
	}
}
