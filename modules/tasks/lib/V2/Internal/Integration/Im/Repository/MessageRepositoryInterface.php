<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Repository;

use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Message;

interface MessageRepositoryInterface
{
	public function getById(int $messageId): ?Message;
}
