<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary;

use Bitrix\AiAssistant\Trigger\Action\BaseAction;
use Bitrix\AiAssistant\Trigger\Service\Dto\TriggerInitDto;
use Bitrix\Main\Loader;

Loader::requireModule('aiassistant');

/**
 * Chat of the AI summary about a single CRM entity: the trigger that opens it and the entity the
 * chat is bound to.
 *
 * The chat is opened empty, so the action writes nothing into it: the caller starts the trigger
 * without the standard first message, and the engine asks the action for neither a text nor the
 * buttons of one. Everything the user reads in the chat is written by the task that owns the first
 * message.
 *
 * The trigger is started only by the explicit path (TriggerExecutor::execute() from the crm
 * controller behind the card button), so the automatic path is refused outright: candidates there
 * are picked by activity, access codes, module and restart rule, and entity binding is not asked
 * about at all. A row that started by itself would reach the executor without an entity id and
 * would take the display queue and the lock from the onboarding trigger.
 *
 * The rights of the element are guarded by the caller alone. The engine has no hook left to ask
 * the action about them: it asks only while preparing the first message, and that message is not
 * sent. A first message brings the guard back with it.
 *
 * Subclasses carry nothing but the two facts that differ between entity types.
 *
 * @internal
 */
abstract class AbstractClientSummaryAction extends BaseAction
{
	/** Messenger entity type the created chat is bound to */
	abstract protected function chatEntityType(): string;

	public function isEntityBound(): bool
	{
		return true;
	}

	public function supportedEntityType(): ?string
	{
		return $this->chatEntityType();
	}

	/**
	 * The engine never starts this trigger on its own.
	 *
	 * @see AbstractClientSummaryAction
	 */
	protected function shouldRunAction(TriggerInitDto $triggerInitDto): bool
	{
		return false;
	}

	/**
	 * Unreachable on the explicit path: TriggerExecutor::runFromTriggerData() resolves the chat
	 * without asking the action to run.
	 */
	protected function runAction(int $userId, int $progressId): bool
	{
		return false;
	}

	/**
	 * The row has to stay live for the chat to be reused on the next click.
	 */
	protected function needCompleteAfterRun(): bool
	{
		return false;
	}
}
