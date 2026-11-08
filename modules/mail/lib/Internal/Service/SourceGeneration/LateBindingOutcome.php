<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

/**
 * What one generation came to in a pass of {@see LateServiceBinder}.
 *
 * What the walk needs out of the three is the one in the middle: work that is still owed and would
 * be done by the very same call once the cause is gone - a mailbox busy with a pass of its
 * migration, a write the database refused. A walk that dropped those would leave the mailbox
 * without a service until a row of the directory happens to appear again, and no row is owed to it.
 *
 * A name the directory does not answer to is none of that: nothing can bind it, and the appearance
 * of the row that could asks for a walk of its own {@see \Bitrix\Mail\Update\LateServiceBindingStepper::request()}.
 * Retrying it inside this walk would only spin.
 */
enum LateBindingOutcome
{
	/** The number reached both sides that are owed it. */
	case Bound;

	/** Nothing was written, and the same call would write it later. The walk has to come back. */
	case Deferred;

	/** Nothing is owed here, now: no rows to bind, or no row of the directory to bind them to. */
	case Skipped;
}
