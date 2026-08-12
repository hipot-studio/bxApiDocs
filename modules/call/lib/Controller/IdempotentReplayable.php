<?php

namespace Bitrix\Call\Controller;

/**
 * Marker interface for controllers that opt in to idempotent-replay short
 * circuit. When {@see \Bitrix\Call\Controller\Filter\UniqueRequestFilter}
 * detects a duplicate requestId on a controller implementing this interface,
 * it asks the controller to short-circuit its action body and reply with a
 * synthetic success payload (`['result' => true, 'replayed' => true]`)
 * instead of returning an error. Without this interface the filter falls
 * back to the legacy REQUEST_NOT_UNIQUE error path for backward compatibility.
 *
 * @internal
 */
interface IdempotentReplayable
{
	/**
	 * Mark the current request as an idempotent replay. Called by the filter
	 * when the requestId is already claimed by a previous (presumably
	 * successful) processing pass. The controller then short-circuits its
	 * action body in {@see IdempotentReplayableTrait::getActionResponse()}.
	 *
	 * @return void
	 */
	public function markIdempotentReplay(): void;
}
