<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Dictionary;

/**
 * One of the seven basic CRM dictionaries, and the only way this domain accepts one: a dictionary is
 * never addressed by string. The value of a case is the identifier the storage keeps the values of that
 * dictionary under.
 *
 * THE SET IS CLOSED AND IS NOT THE SET OF THE PORTAL. The legacy facade also serves the event type and
 * the requisite lists, and the composition of the latter depends on the countries enabled on the portal.
 * A dictionary this domain serves must not appear or disappear with a portal setting, so the seven are
 * spelled out here instead of being taken from the facade.
 *
 * @internal
 */
enum DictionaryType: string
{
	case DealType = 'DEAL_TYPE';
	case ContactType = 'CONTACT_TYPE';
	case Honorific = 'HONORIFIC';
	case CompanyType = 'COMPANY_TYPE';
	case Employees = 'EMPLOYEES';
	case Industry = 'INDUSTRY';
	case Source = 'SOURCE';
}
