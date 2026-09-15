<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Handlers;

use Bitrix\Anonymizer\Public\Context\QuestContext;

/**
 * Event handler for quest completion. Consuming modules implement this interface.
 *
 * Context is not stored in the handler; it is passed only as an argument to onResponse() and onError().
 * Implementations may use a parameterless constructor.
 *
 */
interface QuestHandlerInterface
{
	/**
	 * Called when the quest has received a successful response.
	 *
	 * @param QuestContext $context DTO with questId, provider; command result is available via request by questId.
	 */
	public function onResponse(QuestContext $context): void;

	/**
	 * Called when the quest has failed.
	 *
	 * @param QuestContext $context DTO with questId, provider; context->error contains the error (\Bitrix\Main\Error).
	 */
	public function onError(QuestContext $context): void;
}
