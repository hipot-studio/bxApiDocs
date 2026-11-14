<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

/**
 * Sections a summary of a deal has. The value is the key of the section in the wire form: the
 * strings are normative, so renaming one breaks whoever reads them.
 *
 * The order of the cases is the order the sections are told in. It is a list of its own and not the
 * one of a client ({@see SectionCode}): the two summaries share the shell of the result and nothing
 * of what is inside it.
 *
 * @internal
 */
enum DealSectionCode: string
{
	case Details = 'details';
	case Client = 'client';
	case Communications = 'communications';
	case Products = 'products';
	case Invoices = 'invoices';
}
