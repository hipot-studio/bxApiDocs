<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Mail\Service;

class EmailLinkService
{
	public function getLink(int $id): string
	{
		return '/mail/message/' . $id;
	}
}
