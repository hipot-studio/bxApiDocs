<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Dictionary;

use Bitrix\Crm\Multifield\Type;

/**
 * The communication channel a multifield value belongs to. It picks the dictionary of value types a
 * caller asks for, and the value of a case is the very code an item stores the channel under.
 */
enum CommunicationChannel: string
{
	case Email = Type\Email::ID;
	case Phone = Type\Phone::ID;
	case Im = Type\Im::ID;
	case Web = Type\Web::ID;
}
