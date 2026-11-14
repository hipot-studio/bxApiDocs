<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Communications;

/**
 * Which kind of conversation a record of the history tells about.
 *
 * The three are exactly the deals the speech analysis of CRM writes a summary of - the providers of
 * {@see \Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription::SUPPORTED_ACTIVITY_PROVIDER_IDS}.
 * A kind outside that list carries no summary, so the history has nothing to tell about it.
 *
 * The kinds are told apart in the answer because the fields of a record do not mean the same for all
 * of them: a duration belongs to a call alone, and a question about the last calls is answered by the
 * calls of the history and not by its letters.
 *
 * The values are part of the answer of the tool.
 *
 * @internal
 */
enum CommunicationKind: string
{
	case Call = 'call';
	case Email = 'email';
	case OpenLine = 'openline';
}
