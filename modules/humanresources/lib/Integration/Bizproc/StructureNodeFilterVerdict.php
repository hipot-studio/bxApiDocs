<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

/**
 * Why the node filter took the employee of the event, or why it did not.
 *
 * The verdict is neutral on purpose: the refusal codes of a trigger carry its own prefix, by which its
 * dispatcher tells a refusal of the filter from a failure of the launch, so the translation of a verdict
 * into a code belongs to the trigger and never here.
 */
enum StructureNodeFilterVerdict
{
	case Applicable;
	case NoEmployee;
	case UnresolvedFilter;
	case StaleUserCodes;
	case NoStructureCodes;
	case NoMemberNodes;
	case OutsideSelection;
}
