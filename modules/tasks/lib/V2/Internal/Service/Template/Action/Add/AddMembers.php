<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\Control\TemplateMember;

class AddMembers
{
	public function __invoke(array $fields): void
	{
		(new TemplateMember($fields['ID']))->add($fields);
	}
}
