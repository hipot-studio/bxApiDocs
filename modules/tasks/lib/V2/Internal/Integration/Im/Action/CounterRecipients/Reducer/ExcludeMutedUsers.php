<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Reducer;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;

class ExcludeMutedUsers implements ReducerInterface
{
	public function __invoke(RecipientsResolver $context): void
	{
		$inMuteMembers = $context->taskWithMembers?->inMute ?? [];

		$context->recipients = $context->recipients->filter(
			static fn (Entity\User $user): bool => !in_array($user->getId(), $inMuteMembers, true)
		);
	}
}
