<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Draft\Contract;

use Bitrix\Mail\Internal\Service\Draft\Dto\DraftFromScheduledMessage;
use Bitrix\Main\Result;

interface DraftServiceInterface
{
	public function createFromScheduledMessage(DraftFromScheduledMessage $message): Result;
}
