<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

/**
 * What came of queueing the generation of a summary. By it the launch decides what the person reads
 * in the chat that has just been opened ([P3.T4]).
 *
 * @internal
 */
enum ClientSummaryJobStatus
{
	/** The job is in the queue; the answer will come to the chat by itself. */
	case Queued;

	/** The very same summary is already being written for this person (ALG-01). */
	case AlreadyRunning;

	/** The AI limit of the portal is spent, and the engine refused the request before the model was asked. */
	case LimitExceeded;

	/** Everything else that stopped the job from being created. */
	case Failed;
}
