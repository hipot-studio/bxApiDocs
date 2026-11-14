<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Crm\V2\Internal\Exception\AiSummary\ElementNotReadableException;
use Bitrix\Crm\V2\Internal\Exception\AiSummary\SummaryUnavailableException;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Chat\SummaryChatMessenger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\AbstractClientSummaryAction;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\CompanySummaryAction;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action\Summary\ContactSummaryAction;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Service\SummaryTriggerGateway;
use Bitrix\Crm\V2\Internal\Integration\Im\Chat\CopilotChatBinder;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Opens the AI summary chat about one CRM entity: starts the trigger of its type, binds the chat
 * that comes back to the entity and asks for the summary that is to be written into it.
 *
 * Nothing is ever written into the chat on behalf of the user (AC-002): the trigger is started
 * without its standard first message, and everything the person reads comes from the bot.
 *
 * The job of the summary is queued whether the chat has just been created or reused: a reused chat
 * gets no first message of its own, so without the job no summary would ever come to it (AC-005).
 * What the person reads next is decided by that queueing - by whether one was already running and by
 * what came of this one - and by nothing else ([P3.T4]). The generation itself is not waited for: the
 * identifier of the chat is answered as soon as the chat is there, and the summary arrives in it later
 * as a message of its own (AC-001).
 *
 * The queueing does not stand on the path of the answer either. Collecting the data of the client and
 * calling the queue of the engine cost far more than opening the chat, and none of it is read by the
 * caller, so it is left to a background job: the identifier of the chat is answered first, and the
 * work runs once the answer is gone. The outcomes of the queueing lose nothing by it - every one of
 * them is told in the chat and never in the answer of the action - and neither does the stub: it is the
 * first thing the job does, so the chat that has just opened is not left empty while the data is being
 * collected.
 *
 * The creation of the chat splits the scenario in two. Until the chat exists, any failure refuses
 * the launch. Once it exists, it is already worth opening, so the binding only warns: answering
 * with the default chat would throw away the work that is already done.
 *
 * Both questions of the feature - whether a launch reaches the user at all and whether they may read
 * this very client - are asked here, before anything is started. They are asked here and not by the caller
 * because what follows the chat is an asynchronous job: by the time it runs there is no authorized
 * user left to ask, and a refusal that arrives here costs neither a job nor a call of the engine.
 *
 * @internal
 */
final class ClientSummaryLaunchService
{
	private const LOGGER_ID = 'crm.aiSummary.launch';

	/**
	 * MAP-01: the entity types a summary is written for. The trigger code and the chat binding
	 * literal are read from the action that owns them.
	 *
	 * @var array<int, class-string<AbstractClientSummaryAction>>
	 */
	private const ACTIONS = [
		OwnerType::CONTACT => ContactSummaryAction::class,
		OwnerType::COMPANY => CompanySummaryAction::class,
	];

	private SummaryTriggerGateway $triggerGateway;
	private CopilotChatBinder $chatBinder;
	private ClientSummaryVisibility $visibility;
	private ClientSummaryJobLauncher $jobLauncher;
	private SummaryChatMessenger $messenger;
	private ClientSummaryBackgroundRunner $background;

	public function __construct(
		?SummaryTriggerGateway $triggerGateway = null,
		?CopilotChatBinder $chatBinder = null,
		private ?LoggerInterface $logger = null,
		?ClientSummaryVisibility $visibility = null,
		?ClientSummaryJobLauncher $jobLauncher = null,
		?SummaryChatMessenger $messenger = null,
		?ClientSummaryBackgroundRunner $background = null,
	)
	{
		$this->triggerGateway = $triggerGateway ?? new SummaryTriggerGateway();
		$this->chatBinder = $chatBinder ?? new CopilotChatBinder();
		$this->visibility = $visibility ?? new ClientSummaryVisibility();
		$this->jobLauncher = $jobLauncher ?? new ClientSummaryJobLauncher();
		$this->messenger = $messenger ?? new SummaryChatMessenger();
		$this->background = $background ?? new ClientSummaryBackgroundRunner();
	}

	/**
	 * Tells whether a summary is written for entities of this type at all.
	 */
	public function isSupportedEntityType(int $entityTypeId): bool
	{
		return isset(self::ACTIONS[$entityTypeId]);
	}

	/**
	 * @return int identifier of the chat to open
	 *
	 * @throws \InvalidArgumentException no summary is written for this entity type
	 * @throws SummaryUnavailableException the summary is not available to this user
	 * @throws ElementNotReadableException the user may not read this client
	 * @throws \DomainException|\RuntimeException the engine refused the launch and no chat exists
	 */
	public function launch(int $userId, int $entityTypeId, int $entityId): int
	{
		$actionClass = $this->resolveActionClass($entityTypeId);
		$this->guardAccess($userId, $entityTypeId, $entityId);
		$entityIdValue = (string)$entityId;

		$chatId = $this->triggerGateway->execute($actionClass::TRIGGER_CODE, $userId, $entityIdValue);

		$this->bindChat($chatId, $actionClass::CHAT_ENTITY_TYPE, $entityIdValue);
		$this->deferGeneration($userId, $entityTypeId, $entityId, $chatId);

		return $chatId;
	}

	/**
	 * The generation is handed over to a background job, and a job that never starts or never finishes
	 * costs nothing but a line in the log: the chat is open by then, and a refusal of the launch would
	 * take it away from the person for no reason of theirs.
	 */
	private function deferGeneration(int $userId, int $entityTypeId, int $entityId, int $chatId): void
	{
		$job = function () use ($userId, $entityTypeId, $entityId, $chatId): void
		{
			try
			{
				$this->startGeneration($userId, $entityTypeId, $entityId, $chatId);
			}
			catch (\Throwable $exception)
			{
				$this->logGenerationFailure($chatId, $exception);
			}
		};

		try
		{
			$this->background->run($job);
		}
		catch (\Throwable $exception)
		{
			$this->logGenerationFailure($chatId, $exception);
		}
	}

	private function logGenerationFailure(int $chatId, \Throwable $exception): void
	{
		$this->getLogger()->error('summary generation was not started', [
			'chatId' => $chatId,
			'exceptionClass' => $exception::class,
			'exceptionMessage' => $exception->getMessage(),
		]);
	}

	/**
	 * Asks for the summary and tells the person what came of the asking.
	 *
	 * The stub and the waiting indicator go first (AC-003), before the data of the client is collected
	 * and the queue of the engine is called: those two are seconds, and the chat has been open since the
	 * answer of the action - a chat that stays empty for seconds is exactly what the stub is there to
	 * prevent. What the stub is not sent for is a generation that is already running: its stub is in the
	 * same chat already, and the summary the person waits for is the very same one, so it is asked
	 * beforehand whether there is one ({@see ClientSummaryJobLauncher::isGenerationRunningFor()}).
	 *
	 * The outcome of the queueing is written after the stub and not instead of it. A queued job has
	 * nothing to add to what the stub says; a refusal says what to do next (AC-012, AC-016) and follows
	 * the stub, so the person is never left with the stub alone; a generation that was already running
	 * is answered with nothing at all.
	 *
	 * Nothing here is allowed to refuse the launch: the chat is open by now, and the person is better
	 * off with an open chat carrying a refusal than with no chat at all.
	 *
	 * A type this service opens a chat for and the operation writes no summary for is a disagreement of
	 * two lists ({@see self::ACTIONS} and the supported targets of the operation) and never a state of
	 * the data. It is answered the way every other failure is - a line in the log and a refusal in the
	 * chat - because the chat is open either way, and silence would leave the person with the stub of
	 * a summary that is never coming.
	 */
	private function startGeneration(int $userId, int $entityTypeId, int $entityId, int $chatId): void
	{
		if (!$this->jobLauncher->isSummaryGeneratedFor($entityTypeId, $entityId))
		{
			$this->getLogger()->error('no summary is generated for this entity type', [
				'chatId' => $chatId,
				'entityTypeId' => $entityTypeId,
				'entityId' => $entityId,
			]);

			$this->messenger->sendFailure($chatId);

			return;
		}

		// this launch writes into the chat, and only a running job that writes there too replaces it
		if (!$this->jobLauncher->isGenerationRunningFor($userId, $entityTypeId, $entityId, true))
		{
			$this->messenger->sendPending($chatId);
		}

		$status = $this->jobLauncher->queue(
			$userId,
			$entityTypeId,
			$entityId,
			$chatId,
			ClientSummaryOrigin::Chat,
		);

		match ($status)
		{
			ClientSummaryJobStatus::Queued, ClientSummaryJobStatus::AlreadyRunning => null,
			ClientSummaryJobStatus::LimitExceeded => $this->messenger->sendLimitExceeded($chatId),
			ClientSummaryJobStatus::Failed => $this->messenger->sendFailure($chatId),
		};
	}

	/**
	 * The guard of a launch, asked of the provider that draws the button so that a launch is refused
	 * by exactly what refuses the button - the very question that button is drawn by, the engine of the
	 * triggers included. A page that was open while an admin closed the engine still carries the button,
	 * and a press of it is told what is the matter instead of running into the generic failure of the
	 * engine.
	 *
	 * The measure of the feature comes first, and it no longer costs one option: at a positive grant it
	 * reads the switch of the portal, and reading the switch builds the manager of the settings and sends
	 * the load event around the portal - the cost of it is weighed where the measure is declared. The
	 * right to read the element is asked second, because between the drawing of the button and the press
	 * of it the right may be gone (ERR-009).
	 *
	 * The two refusals are two exceptions and not one, because the caller answers them with two
	 * different codes of API-01: the guard is asked here once, and the controller reads the answer off
	 * the type of the exception instead of asking the same two questions over again.
	 */
	private function guardAccess(int $userId, int $entityTypeId, int $entityId): void
	{
		if (!$this->visibility->isLaunchable($userId, $entityTypeId))
		{
			throw new SummaryUnavailableException();
		}

		if (!$this->visibility->isElementReadable($userId, $entityTypeId, $entityId))
		{
			throw new ElementNotReadableException($entityTypeId, $entityId);
		}
	}

	/**
	 * @return class-string<AbstractClientSummaryAction>
	 */
	private function resolveActionClass(int $entityTypeId): string
	{
		return self::ACTIONS[$entityTypeId]
			?? throw new \InvalidArgumentException("No client summary is defined for entity type {$entityTypeId}")
		;
	}

	private function bindChat(int $chatId, string $entityType, string $entityId): void
	{
		try
		{
			$this->chatBinder->bind($chatId, $entityType, $entityId);
		}
		catch (\Throwable $exception)
		{
			$this->getLogger()->warning('summary chat binding failed', [
				'chatId' => $chatId,
				'entityType' => $entityType,
				'entityId' => $entityId,
				'exceptionClass' => $exception::class,
				'exceptionMessage' => $exception->getMessage(),
			]);
		}
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}
}
