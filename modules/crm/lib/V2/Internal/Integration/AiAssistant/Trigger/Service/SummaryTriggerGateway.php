<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Service;

use Bitrix\AiAssistant\Config\Feature;
use Bitrix\AiAssistant\Trigger\Dto\ChatContextDto;
use Bitrix\AiAssistant\Trigger\Service\TriggerExecutor;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\AbstractClientSummaryAction;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\CompanySummaryAction;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\ContactSummaryAction;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

/**
 * The only place where CRM starts a summary trigger.
 *
 * The chat is asked for and nothing else: the first message in it - the greeting of the trigger as
 * much as the summary itself - is written by the task that owns it, so the standard message of the
 * engine is refused here and no message is sent on behalf of the user.
 *
 * @internal
 */
class SummaryTriggerGateway
{
	/**
	 * The trigger a summary of each client type is opened through. The codes are read off the actions
	 * that own them, and the classes are named here and not loaded: `::class` resolves without the
	 * module of the engine, which a portal may not have at all.
	 *
	 * @var array<int, class-string<AbstractClientSummaryAction>>
	 */
	private const ACTIONS = [
		OwnerType::CONTACT => ContactSummaryAction::class,
		OwnerType::COMPANY => CompanySummaryAction::class,
	];

	/**
	 * What the engine answered about a trigger and a user.
	 *
	 * Every question costs two queries of the engine and it caches none of them, while a card asks the
	 * same one twice - once for the button of its header and once for the hint pointing at that button.
	 *
	 * The memory lives as long as this object does, and the object is held by the service locator for
	 * the whole process, so it outlives a single request only where the process does - a run of the
	 * tests, an agent, a console script. The answer is kept per user, so no grant of one person reaches
	 * another; what it does not survive is the rights of one and the same user changing mid-process. A
	 * test doing that has to substitute the instance in the locator or build a gateway of its own.
	 *
	 * @var array<string, bool>
	 */
	private array $renderableTriggers = [];

	public function __construct(
		private ?TriggerExecutor $executor = null,
	)
	{
	}

	/**
	 * Starts the trigger and tells which chat to open.
	 *
	 * Nothing is caught here: every failure of this step happens before a chat exists, and the
	 * exceptions of the engine carry the error codes the caller tells the refusal reasons apart by.
	 *
	 * @return int identifier of the chat to open
	 *
	 * @throws \Bitrix\Main\LoaderException the engine module is not available
	 * @throws \DomainException the trigger is unknown, inactive or forbidden for the user
	 * @throws \RuntimeException the chat could not be created
	 */
	public function execute(string $triggerCode, int $userId, string $entityId): int
	{
		$result = $this->getExecutor()->execute(
			triggerCode: $triggerCode,
			userId: $userId,
			entityId: $entityId,
			context: new ChatContextDto(entityId: $entityId),
			postInitialMessage: false,
		);

		return $result->chatId;
	}

	/**
	 * Whether a launch of this kind would be carried out instead of refused.
	 *
	 * Everything {@see TriggerExecutor::execute()} refuses before a chat exists is asked about here, and
	 * in the same order: the switch of the engine, then the row of the trigger itself - it has to be
	 * registered, active, and open to this user. All three are states a portal arrives at on its own: the
	 * switch is off by default, and the rows are written by the agent of the registration on a schedule,
	 * so a portal that has just been updated has the switch on and no rows yet. A surface drawn without
	 * asking would answer such a press with the generic failure of the engine.
	 *
	 * The type of the card picks the trigger. Without one the question is about the user rather than
	 * about a card - the hint of the onboarding is built from the templates of both cards and carries no
	 * type - and is answered by the first trigger this user may launch at all.
	 */
	public function isLaunchableFor(int $userId, ?int $entityTypeId = null): bool
	{
		if (!$this->isEngineEnabled())
		{
			return false;
		}

		foreach (self::triggerCodesOf($entityTypeId) as $triggerCode)
		{
			if ($this->isTriggerRenderable($triggerCode, $userId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the engine of the triggers is open on this portal at all.
	 *
	 * The first thing {@see TriggerExecutor::execute()} asks, and the option behind it is off by
	 * default - so it is asked first here too, and the rows of the triggers are left unqueried on the
	 * portals that never turned the engine on.
	 *
	 * A question and not a launch, so an absent module is an answer here and not an exception: a portal
	 * without the engine has no trigger to start either way.
	 */
	public function isEngineEnabled(): bool
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return false;
		}

		// the singleton of the engine and not a build of the locator: the flag is read off one instance
		return Feature::getInstance()->isCopilotTriggersEnabled();
	}

	/**
	 * The row of the trigger as the engine sees it: registered, active and open to this user, all three
	 * in one question of the engine ({@see TriggerExecutor::resolveRenderableAction()}) so that CRM keeps
	 * no second opinion about any of them.
	 */
	private function isTriggerRenderable(string $triggerCode, int $userId): bool
	{
		return $this->renderableTriggers["{$triggerCode}|{$userId}"] ??=
			$this->getExecutor()->resolveRenderableAction($triggerCode, $userId) !== null
		;
	}

	/**
	 * @return string[]
	 */
	private static function triggerCodesOf(?int $entityTypeId): array
	{
		$actionClasses = $entityTypeId === null
			? array_values(self::ACTIONS)
			: array_filter([self::ACTIONS[$entityTypeId] ?? null])
		;

		return array_map(static fn(string $actionClass): string => $actionClass::TRIGGER_CODE, $actionClasses);
	}

	protected function getExecutor(): TriggerExecutor
	{
		Loader::requireModule('aiassistant');

		return $this->executor ??= ServiceLocator::getInstance()->get(TriggerExecutor::class);
	}
}
