<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany;

/**
 * Block codes of the "My company" section in display order: the composition walks cases(),
 * so the declaration order is the order the screen renders.
 *
 * The portal section and the subscription card have no code here: the screen draws the portal
 * itself, and the subscription is an inner card of the tariff block.
 */
enum BlockId: string
{
	case Employees = 'employees';
	case Tariff = 'tariff';
	case WhatsNew = 'whatsNew';
	case BitrixAnswers = 'bitrixAnswers';
	case Support = 'support';
	case PersonalAccount = 'personalAccount';
	case Integrator = 'integrator';
	case ImplementationSpecialists = 'implementationSpecialists';
	case Purchases = 'purchases';
}
