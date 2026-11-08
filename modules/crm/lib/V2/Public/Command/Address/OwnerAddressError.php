<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Address;

use Bitrix\Main\Error;

/**
 * The closed set of refusals a command of an owner address reports as an error of its result.
 *
 * The case name is the code that leaves the domain: it travels as {@see Error::getCode()} of an
 * unsuccessful {@see \Bitrix\Main\Result}, and a consumer recovers it with
 * `OwnerAddressError::tryFrom($error->getCode())`. The value of a code is declared here and nowhere else -
 * the internal layer raising the error takes it from here as well - while the text of the message stays
 * with that layer and is not part of this contract.
 *
 * `ADDRESS_TYPE_UNKNOWN` and `ADDRESS_ONLY_REQUISITE_NOT_CREATED` belong to an addition alone: the type of
 * an address is a value of the address type dictionary of CRM, and a number the dictionary does not
 * describe names no type to store an address as, while the requisite an address lives on is chosen when the
 * address is added and never again. A change and a deletion take neither a type nor a requisite at all.
 *
 * `ADDRESS_COUNTRY_CODE_MISMATCH` belongs to both a change and an addition, and a change is answered for by
 * the address the row would hold afterwards and not by the fields the change names: a change naming the code
 * alone is refused or accepted by the country that is already stored.
 *
 * Not every refusal of a command is one of these. An owner or an address the user may not have is
 * {@see \Bitrix\Main\ObjectNotFoundException}, and a failure of the storage is an exception of the storage.
 * A result may also carry an error raised by a layer underneath the group - the requisites of CRM and the
 * ORM report their own - and such an error names no case here: a consumer matches the cases it knows and
 * treats the rest as a failure of the portal rather than as something the caller can fix.
 */
enum OwnerAddressError: string
{
	/** The address has no value in any of its fields, and an empty address is not one that is stored. */
	case ADDRESS_IS_EMPTY = 'ADDRESS_IS_EMPTY';

	/** The owner has no requisite to store an address on, and one could not be created for it. */
	case ADDRESS_ONLY_REQUISITE_NOT_CREATED = 'ADDRESS_ONLY_REQUISITE_NOT_CREATED';

	/** The requisite the address would be stored on already holds an address of this type. */
	case ADDRESS_TYPE_OCCUPIED = 'ADDRESS_TYPE_OCCUPIED';

	/** The address type dictionary of CRM describes no type of the given identifier. */
	case ADDRESS_TYPE_UNKNOWN = 'ADDRESS_TYPE_UNKNOWN';

	/**
	 * The code of the country does not hold up against the country the address would be stored with: the
	 * country dictionary of the portal either describes no country of that code, or names it differently,
	 * or the address carries a code and no country at all.
	 */
	case ADDRESS_COUNTRY_CODE_MISMATCH = 'ADDRESS_COUNTRY_CODE_MISMATCH';
}
