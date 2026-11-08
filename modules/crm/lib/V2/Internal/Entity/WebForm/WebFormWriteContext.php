<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

/**
 * Which write a question about the change contract of a CRM form is asked on behalf of.
 *
 * The contract is not one set. A form takes its activity while it is being created and never
 * through a change of its configuration, so one and the same path is written by one write and
 * refused by the other. Every answer about what may be written is therefore given for a context,
 * and the context carries no default value on purpose: a caller left to an implied one would
 * silently get the wider set of a creation and accept on a change what a change may not write.
 *
 * Which context an input stands in is known to the action that received it and travels from there.
 * The change set itself holds values and is read in either context.
 *
 * @internal
 */
enum WebFormWriteContext
{
	case Creation;
	case Change;
}
