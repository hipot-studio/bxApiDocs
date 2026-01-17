<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Member\Service\TemplateMemberService;

class ResetCache
{
	public function __invoke(array $fields): void
	{
		TemplateMemberService::invalidate();
	}
}
