<?php

namespace Bitrix\Sign\Type\B2e;

/**
 * Receipt mark scenario, chosen by the message trigger that resolves the recipient.
 */
enum ReceiptScenario
{
	// SC_001: the document was initiated by the company.
	case CompanyInitiated;

	// SC_002: the document was initiated by the employee.
	case EmployeeInitiated;
}
