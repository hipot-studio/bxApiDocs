<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Voximplant;

use Bitrix\Crm\ItemIdentifier;

/**
 * A call of the telephony as the summary sees it: whom the call is with and who took part in it.
 *
 * Both facts come of one load, because they are read of the same row of the same module: asking the
 * telephony twice would let the client and the participants answer about two different states of a
 * call that is being hung up right now.
 *
 * The client is the entity a summary may be made of - a contact or a company - and is absent for a
 * lead and for a call bound to nothing. A call without a client is still a call: telling it apart
 * from a call that never existed is what keeps a guess at foreign call identifiers from answering
 * whom they are with.
 *
 * @internal
 */
final class ResolvedCall
{
	/**
	 * @param int[] $participantIds Identifiers of the portal users the call went through.
	 */
	public function __construct(
		private readonly ?ItemIdentifier $client,
		private readonly array $participantIds,
	)
	{
	}

	public function getClient(): ?ItemIdentifier
	{
		return $this->client;
	}

	public function hasClient(): bool
	{
		return $this->client !== null;
	}

	/**
	 * @return int[]
	 */
	public function getParticipantIds(): array
	{
		return $this->participantIds;
	}

	public function hasParticipant(int $userId): bool
	{
		return $userId > 0 && in_array($userId, $this->participantIds, true);
	}
}
