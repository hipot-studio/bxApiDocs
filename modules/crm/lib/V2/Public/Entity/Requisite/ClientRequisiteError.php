<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * The closed set of refusals the choice of a client requisite reports.
 *
 * The case name is the code that leaves the domain: it travels as {@see Error::getCode()}, and a
 * consumer recovers it with `ClientRequisiteError::tryFrom($error->getCode())`. Every refusal is one
 * of these - neither an exception of the storage boundary nor an error text of its own ever reaches
 * a caller.
 *
 * Each case deliberately covers several causes, and the message tells them apart no better than the
 * code does. `OWNER_NOT_FOUND` answers both an entity that is not there and one the user may not
 * see, because a separate answer would reveal that the entity exists. `REQUISITE_NOT_ALLOWED`
 * answers a requisite that is missing, one that belongs to somebody else, one the user may not read
 * and an entity that names no client at all - telling them apart would reveal who the client is and
 * which requisites they have.
 */
enum ClientRequisiteError: string
{
	case OWNER_NOT_FOUND = 'OWNER_NOT_FOUND';
	case REQUISITE_NOT_ALLOWED = 'REQUISITE_NOT_ALLOWED';
	case BANK_REQUISITE_NOT_ALLOWED = 'BANK_REQUISITE_NOT_ALLOWED';

	public function getMessage(): string
	{
		Loc::loadMessages(__FILE__);

		return (string)Loc::getMessage('CRM_V2_CLIENT_REQUISITE_ERROR_' . $this->value);
	}

	public function toError(): Error
	{
		return new Error($this->getMessage(), $this->value);
	}
}
