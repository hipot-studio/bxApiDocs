<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\ItemIdentifier;

/**
 * The latest call of a client an AI summary was made of: the text of the summary, whose card the call
 * sits on and the job result itself.
 *
 * The owner is carried explicitly because {@see \Bitrix\Crm\Summary\ClientSummary} answers the latest
 * deal of the client whatever the call belongs to, which is the wrong answer for a call of the client
 * itself. The job result is carried because the aggregates take it as an argument.
 *
 * The shape belongs to the service and never reaches the wire: what the summary tells about the call
 * is written by the reader of the communications.
 *
 * @internal
 */
final class LastCallSummary
{
	/**
	 * @param string $text Never empty: a call without a summary is not a last call summary at all.
	 */
	public function __construct(
		public readonly string $text,
		public readonly ItemIdentifier $owner,
		public readonly Result $jobResult,
	)
	{
	}
}
