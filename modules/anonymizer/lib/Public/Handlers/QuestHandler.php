<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Handlers;

use Bitrix\Anonymizer\Public\Context\QuestContext;

/**
 * Base handler for quest completion. Context is passed into onResponse() and onError(), not stored in the handler.
 */
abstract class QuestHandler implements QuestHandlerInterface
{
	/**
	 * @param QuestContext $context Quest context with provider and questId.
	 */
	abstract public function onResponse(QuestContext $context): void;

	/**
	 * @param QuestContext $context Quest context with error set in context->error.
	 */
	abstract public function onError(QuestContext $context): void;
}
