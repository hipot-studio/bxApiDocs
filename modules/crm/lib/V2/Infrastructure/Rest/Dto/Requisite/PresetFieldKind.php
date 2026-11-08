<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

/**
 * What an entry of the composition of a preset names, and therefore how its name is to be read.
 *
 * The domain tells the three apart by two marks that answer two different questions
 * ({@see \Bitrix\Crm\V2\Public\Entity\Requisite\PresetField}); a contract answered over the wire states
 * one of the three instead, so a client reads a single value rather than working out which pair of
 * booleans is possible. The three are exhaustive: an entry of the composition is always one of them.
 *
 * A backed enum rather than a bare string, so the machine description of the API answers with the three
 * values a client may expect. The values are part of the published contract - a case may be added
 * compatibly, but neither renamed nor removed.
 */
enum PresetFieldKind: string
{
	/**
	 * A field of the requisite contract, named as the contract of the group names it
	 * ({@see AbstractOwnerRequisiteDto}). The only kind a write may address.
	 */
	case RequisiteField = 'requisiteField';

	/**
	 * The marker that says the preset shows the address of the requisite. It names no field: the
	 * addresses of a requisite are read as {@see RequisiteAddressDto} and the name of such an entry is
	 * empty.
	 */
	case Address = 'address';

	/**
	 * A user field of the portal, named as the portal names it. The group publishes no name of its own
	 * for one and answers for none - it is in the composition because the interface shows it.
	 */
	case UserField = 'userField';
}
