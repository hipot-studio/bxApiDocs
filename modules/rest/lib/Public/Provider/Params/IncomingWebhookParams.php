<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Params;

use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Provider\Params\SortInterface;

final class IncomingWebhookParams extends GridParams
{
	public function __construct(
		public IncomingWebhookFilter $webhookFilter,
		PagerInterface $pager,
		?SortInterface $sort = null,
	)
	{
		parent::__construct(
			pager: $pager,
			sort: $sort,
		);
	}
}
