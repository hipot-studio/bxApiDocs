<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Vote;

use Bitrix\Im\V2\Error;

class VoteError extends Error
{
	public const INVALID_VALUE = 'VOTE_INVALID_VALUE';
}
