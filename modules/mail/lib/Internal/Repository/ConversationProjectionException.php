<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Repository;

use Bitrix\Main\SystemException;

/**
 * The conversation projection cannot be written or is inconsistent with the messages it projects:
 * a member of another mailbox, a conversation that no longer exists, a rejected write.
 */
class ConversationProjectionException extends SystemException
{
}
