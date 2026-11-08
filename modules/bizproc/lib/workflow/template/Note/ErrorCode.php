<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Note;

use Bitrix\Bizproc\Error;

/**
 * The refusals a note operation answers with. The code is the whole contract: the editor renders its
 * own text for every one of them, so no message is attached here.
 *
 * A missing note or message answers ACCESS_DENIED as well - whether the object was there is not
 * disclosed; completing and deleting are the exception and answer a neutral success (ALG-04).
 */
enum ErrorCode: string
{
	case AccessDenied = 'ACCESS_DENIED';
	case EmptyMessage = 'EMPTY_MESSAGE';
	case MessageTooLong = 'MESSAGE_TOO_LONG';
	case NoteLimitReached = 'NOTE_LIMIT_REACHED';
	case MessageLimitReached = 'MESSAGE_LIMIT_REACHED';
	case MentionLimitReached = 'MENTION_LIMIT_REACHED';
	case RevisionMismatch = 'REVISION_MISMATCH';

	public function toError(): Error
	{
		return Error::fromCode($this->value);
	}
}
