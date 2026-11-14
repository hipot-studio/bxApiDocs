<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

/**
 * Sections a summary of a client has. The value is the key of the section in the wire form: the
 * strings are normative, so renaming one breaks whoever reads them.
 *
 * The list belongs to the client summary and not to the shell of the result: a summary of a deal
 * has sections of its own.
 *
 * @internal
 */
enum SectionCode: string
{
	case Profile = 'profile';
	case Deals = 'deals';
	case Communications = 'communications';
	case Aggregates = 'aggregates';
}
