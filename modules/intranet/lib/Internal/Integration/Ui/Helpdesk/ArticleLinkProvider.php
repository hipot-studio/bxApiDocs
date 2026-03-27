<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Ui\Helpdesk;

use Bitrix\UI\Helpdesk\Url;

class ArticleLinkProvider
{
	private Url $helpdeskUrlService;

	public function __construct()
	{
		$this->helpdeskUrlService = new Url();
	}

	public function getByCode(string $code): string
	{
		return $this->helpdeskUrlService->getByCodeArticle($code)->getUri();
	}
}
