<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

/**
 * What the tab of the call card shows about the summary of the client (DTO-01).
 *
 * The value of a case is what travels on the wire: the phrases of the states live on the frontend, and
 * the server names a state rather than words it.
 *
 * Two of the cases are never answered by {@see ClientSummaryStateReader}, and that is the contract and
 * not an omission: an AI limit is spent before a row of the job is written, and a queueing that failed
 * wrote no row either, so neither leaves anything to read back. Both reach the tab as a signal of the
 * launch itself, and a later read of the state honestly answers `none`.
 *
 * @internal
 */
enum ClientSummaryState: string
{
	/** No job, or a finished one - successful or not - out of the freshness window: the tab offers to launch. */
	case None = 'none';

	/** A pending job is being executed (AC-008). */
	case Running = 'running';

	/** A fresh successful job, and the summary travels with the state (AC-010, AC-011). */
	case Ready = 'ready';

	/** The job ended in an error, or the answer of the model broke the contract (AC-012). */
	case Failed = 'failed';

	/** The launch was refused by the AI limits of the portal (AC-013). */
	case LimitExceeded = 'limit_exceeded';
}
