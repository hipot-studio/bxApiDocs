<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

/**
 * Where a generation of a client summary was asked for. It travels with the job the same way the
 * chat does - seeded into b_crm_ai_queue.RESULT at launch and read back in the callback - because the
 * asynchronous finish carries no context of the launch (R-3).
 *
 * What it decides is what an absent chat means. A launch from the chat that ends with no chat to
 * write into is a fault and is written to the log; a launch from the tab of the call card never has
 * one, and the answer reaches the person by the signal instead ([P1.T4]).
 *
 * @internal
 */
enum ClientSummaryOrigin: string
{
	/** The button of the CRM card: the answer is delivered as a message of the copilot chat. */
	case Chat = 'chat';

	/** The tab of the call card: there is no chat, and the tab is told by the signal. */
	case CallCardTab = 'call_card_tab';
}
