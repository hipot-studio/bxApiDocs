<?php

declare(strict_types=1);

namespace Bitrix\AI\Service\RequestContext;

/**
 * Kind of an outbound AI request, as seen by the sender.
 *
 * The receiver lays its queue out by service class; the class is derived from this
 * and from nothing else, so that the signed claim and the applied priority cannot diverge.
 */
enum CallType: string
{
	/** A user is waiting for the answer: copilot turn, agent-node search. */
	case Interactive = 'interactive';

	/** An agent loop: nobody watches a single step, but the run as a whole is user-initiated. */
	case Agent = 'agent';

	/** No user in the context: indexing, background analytics. */
	case Background = 'background';
}
