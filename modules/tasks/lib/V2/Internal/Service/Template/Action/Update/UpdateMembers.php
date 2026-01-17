<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Control\TemplateMember;

class UpdateMembers
{
	public function __invoke(array $fields): void
	{
		(new TemplateMember($fields['ID']))->set($fields);
	}
}
