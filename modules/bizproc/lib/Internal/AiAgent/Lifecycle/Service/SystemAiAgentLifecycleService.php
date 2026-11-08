<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\ManagedResourceCleanupBudget;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\ManagedResourceCleanupInterface;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\ManagedResourceCleanupResult;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\EffectiveConfiguration;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentIdentity;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\BlockCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Constant;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstanceState;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentInstanceRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentResourceRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\AgentChatbotsExtractor;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\AiAgentStartResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\SystemTemplateActivationService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduledTriggerSyncService;
use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentActivationParameters;
use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentContext;
use Bitrix\Bizproc\Public\Command\AiAgent\Enum\SystemAiAgentErrorCode;
use Bitrix\Bizproc\Public\Command\AiAgent\Enum\SystemAiAgentLifecycleOutcome;
use Bitrix\Bizproc\Public\Command\AiAgent\Result\SystemAiAgentLifecycleResult;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Im\Model\BotTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Psr\Log\LoggerInterface;

/**
 * Orchestrator of the managed lifecycle of a system AI agent: it enables an instance of the agent for one
 * external context and removes such an instance completely.
 *
 * One named lock of the logical identity covers one bounded pass of either operation, and this service is its
 * only owner: neither the repositories nor {@see ManagedAgentResourceRegistry} acquire it. The lock is taken
 * before the operation scope of the registry is announced and is released in the same finally block that closes
 * that scope, therefore every producer called inside the operation reuses the lock instead of waiting for it.
 *
 * Two invariants of this class are easy to break and are named here on purpose:
 * <ul>
 * <li> a database transaction never spans the start of a workflow - a transaction covers writes of the module
 *      only, so a starting process can never be rolled back together with one;
 * <li> a final success of an enabling is never returned while a compensation is unfinished - a failure after the
 *      copy exists moves the instance into removal and answers with the state of that removal.
 * </ul>
 *
 * Both of them rest on a precondition that belongs to the caller: an operation is called outside a transaction of
 * that caller. A nested startTransaction() is a savepoint and a nested commitTransaction() commits nothing before
 * the outer COMMIT, so a transaction of the caller would enclose the very things these invariants keep apart - the
 * start of a workflow, the created bots, the written schedules and the confirmed durability of a continuation -
 * while the named lock is released with those writes still uncommitted, and a rollback of the caller would take a
 * started process and a promised continuation with it. Nothing here checks the precondition: the kernel exposes no
 * public reading of the transaction level.
 *
 * The service is the single entry point of both algorithms: the public commands call {@see self::enable()} and
 * {@see self::disable()}, and the background continuation of an unfinished removal calls
 * {@see self::continueRemoval()} with a non blocking acquisition of the very same lock, so no second
 * implementation of the removal exists.
 *
 * Nothing of the caller leaves the module: the result carries the outcome and a stable error code, and the
 * structured journal carries an HMAC token of the identity instead of its hash, never the original context id,
 * the parameters, the constants or the text of an exception.
 */
final class SystemAiAgentLifecycleService
{
	public const SERVICE_CODE = 'bizproc.ai_agent.lifecycle.service';

	/**
	 * Waiting time of a synchronous operation for the named lock, the same limit the creation barrier uses.
	 */
	public const FOREGROUND_LOCK_TIMEOUT_SECONDS = ManagedAgentResourceRegistry::LOCK_TIMEOUT_SECONDS;

	/**
	 * The background continuation never waits: a busy record belongs to a running operation and is skipped.
	 */
	public const BACKGROUND_LOCK_TIMEOUT_SECONDS = 0;

	/**
	 * Signing purpose of the identity token of the journal: the token correlates the records of one instance
	 * without exposing the identity hash it is derived from.
	 */
	public const LOG_IDENTITY_PURPOSE = 'bizproc.system_ai_agent.log_identity.v1';

	public const LOGGER_ID = 'bizproc.system_ai_agent';

	public const OPERATION_ENABLE = 'enable';

	public const OPERATION_DISABLE = 'disable';

	public const OPERATION_TRANSFER_OWNER = 'transfer_owner';

	/**
	 * Watchdog deadline of an instance that is being created: an enabling that never reached its final state is
	 * continued as a removal only after this deadline, so a live operation is not compensated by mistake.
	 */
	public const ENABLING_WATCHDOG_SECONDS = 600;

	/**
	 * Registered rows one batch of a removal pass reads, additionally bounded by the rows the pass has left.
	 */
	private const REGISTRY_BATCH_LIMIT = 50;

	/**
	 * Delays of the retries a failed pass is charged with, in minutes; every further failure keeps the last one.
	 */
	private const RETRY_DELAY_MINUTES = [1, 5, 15, 60];

	private const CODE_LOCK_BUSY = 'AI_AGENT_LOCK_BUSY';

	private const CODE_LOCK_FAILED = 'AI_AGENT_LOCK_FAILED';

	private const CODE_REGISTRY_UNAVAILABLE = 'AI_AGENT_REGISTRY_UNAVAILABLE';

	private const CODE_REPOSITORY_UNAVAILABLE = 'AI_AGENT_REPOSITORY_UNAVAILABLE';

	private const CODE_STORAGE_FAILED = 'AI_AGENT_STORAGE_FAILED';

	private const CODE_IDENTITY_MISMATCH = 'AI_AGENT_IDENTITY_MISMATCH';

	private const CODE_USER_UNUSABLE = 'AI_AGENT_USER_UNUSABLE';

	private const CODE_COPY_ABSENT = 'AI_AGENT_COPY_ABSENT';

	private const CODE_COPY_INVARIANT_BROKEN = 'AI_AGENT_COPY_INVARIANT_BROKEN';

	private const CODE_COPY_CUSTOMIZED = 'AI_AGENT_COPY_CUSTOMIZED';

	private const CODE_CONFIGURATION_CHANGED = 'AI_AGENT_CONFIGURATION_CHANGED';

	private const CODE_ENABLING_IN_PROGRESS = 'AI_AGENT_ENABLING_IN_PROGRESS';

	private const CODE_CLEANUP_IN_PROGRESS = 'AI_AGENT_CLEANUP_IN_PROGRESS';

	private const CODE_COPY_FAILED = 'AI_AGENT_COPY_FAILED';

	private const CODE_BOT_MODULE_MISSING = 'AI_AGENT_BOT_MODULE_MISSING';

	private const CODE_RESOURCE_OWNERSHIP_FAILED = 'AI_AGENT_RESOURCE_OWNERSHIP_FAILED';

	private const CODE_START_FAILED = 'AI_AGENT_START_FAILED';

	private const CODE_SETUP_FAILED = 'AI_AGENT_SETUP_FAILED';

	private const CODE_PUBLISH_FAILED = 'AI_AGENT_PUBLISH_FAILED';

	private const CODE_PARTICIPANT_MISSING = 'AI_AGENT_PARTICIPANT_MISSING';

	private const CODE_RESOURCES_REMAIN = 'AI_AGENT_RESOURCES_REMAIN';

	private const CODE_TEMPLATE_STILL_PRESENT = 'AI_AGENT_TEMPLATE_STILL_PRESENT';

	private const CODE_CONTINUATION_UNCONFIRMED = 'AI_AGENT_CONTINUATION_UNCONFIRMED';

	private const LOG_MESSAGE = 'System AI agent lifecycle operation';

	private const COPY_SELECT = [
		'ID',
		'TYPE',
		'ACTIVE',
		'SYSTEM_CODE',
		'ACTIVATED_BY',
		'ACTIVATED_AT',
		'MODULE_ID',
		'ENTITY',
		'DOCUMENT_TYPE',
		'TEMPLATE',
		'PARAMETERS',
		'CONSTANTS',
	];

	/**
	 * @var array<string, ManagedResourceCleanupInterface> resource type value => its cleanup participant
	 */
	private array $cleanupParticipants = [];

	private readonly SystemAiAgentConfigurationResolver $configurationResolver;

	private readonly SystemAiAgentCapabilityValidator $capabilityValidator;

	private readonly ?ManagedAgentResourceRegistry $registry;

	private readonly SystemTemplateActivationService $activationService;

	private readonly ScheduledTriggerSyncService $scheduleSyncService;

	private readonly ?ManagedAgentInstanceRepositoryInterface $instanceRepository;

	private readonly ?ManagedAgentResourceRepositoryInterface $resourceRepository;

	private ?LoggerInterface $logger;

	private bool $loggerResolved = false;

	/**
	 * @param iterable<ManagedResourceCleanupInterface> $cleanupParticipants participants of a removal pass; the
	 *  traversal order comes from {@see ManagedAgentResourceType::cleanupOrder()} and a participant is matched by
	 *  its own {@see ManagedResourceCleanupInterface::type()}, because one class may serve several types
	 */
	public function __construct(
		iterable $cleanupParticipants,
		private readonly SystemAiAgentResolver $resolver = new SystemAiAgentResolver(),
		private readonly TemplateRevisionService $revisionService = new TemplateRevisionService(),
		private readonly SetupTemplateService $setupTemplateService = new SetupTemplateService(),
		private readonly AgentChatbotsExtractor $chatbotsExtractor = new AgentChatbotsExtractor(),
		?SystemAiAgentConfigurationResolver $configurationResolver = null,
		?SystemAiAgentCapabilityValidator $capabilityValidator = null,
		?ManagedAgentResourceRegistry $registry = null,
		?SystemTemplateActivationService $activationService = null,
		?ScheduledTriggerSyncService $scheduleSyncService = null,
		?ManagedAgentInstanceRepositoryInterface $instanceRepository = null,
		?ManagedAgentResourceRepositoryInterface $resourceRepository = null,
		?LoggerInterface $logger = null,
		private readonly int $cleanupRowLimit = ManagedResourceCleanupBudget::DEFAULT_ROW_LIMIT,
		private readonly float $cleanupPassSeconds = ManagedResourceCleanupBudget::DEFAULT_PASS_SECONDS,
	)
	{
		foreach ($cleanupParticipants as $participant)
		{
			if ($participant instanceof ManagedResourceCleanupInterface)
			{
				$this->cleanupParticipants[$participant->type()->value] = $participant;
			}
		}

		$locator = ServiceLocator::getInstance();

		$this->configurationResolver = $configurationResolver ?? new SystemAiAgentConfigurationResolver();
		// The very participants of a removal pass answer which resource types are supported at all, so an agent
		// whose graph cannot be removed completely is never enabled.
		$this->capabilityValidator = $capabilityValidator
			?? new SystemAiAgentCapabilityValidator($this->cleanupParticipants)
		;
		// One shared registry per request: the operation scope, the one time markers and the classification of a
		// template live in its memory, and a registry of its own would not see the operation of this service.
		$this->registry = $registry ?? (
			$locator->has(ManagedAgentResourceRegistry::SERVICE_CODE)
				? $locator->get(ManagedAgentResourceRegistry::SERVICE_CODE)
				: null
		);
		$this->activationService = $activationService ?? $locator->get(SystemTemplateActivationService::class);
		$this->scheduleSyncService = $scheduleSyncService ?? $locator->get(ScheduledTriggerSyncService::class);
		$this->instanceRepository = $instanceRepository ?? Container::getManagedAgentInstanceRepository();
		$this->resourceRepository = $resourceRepository ?? Container::getManagedAgentResourceRepository();
		$this->logger = $logger;
	}

	/**
	 * Enables the system agent for the external context, or confirms that the very same configuration is already
	 * enabled.
	 *
	 * An expected rejection is a value of the result and never an exception, so a malformed pair of a system code
	 * and a context comes back with a stable code instead of leaving the module.
	 */
	public function enable(
		string $systemCode,
		SystemAiAgentContext $context,
		int $userId,
		SystemAiAgentActivationParameters $activation,
	): SystemAiAgentLifecycleResult
	{
		try
		{
			$identity = ManagedAgentIdentity::create($systemCode, $context);
		}
		catch (ArgumentException $exception)
		{
			return $this->rejectInput(self::OPERATION_ENABLE, $exception);
		}

		if (!self::isLaunchingUserUsable($userId))
		{
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				SystemAiAgentErrorCode::InvalidUser,
				self::CODE_USER_UNUSABLE,
			);
		}

		return $this->runUnderLock(
			self::OPERATION_ENABLE,
			$identity,
			self::FOREGROUND_LOCK_TIMEOUT_SECONDS,
			fn(): SystemAiAgentLifecycleResult => $this->runEnable($identity, $userId, $activation),
		);
	}

	/**
	 * Removes the managed instance of the external context completely, or confirms that no binding exists.
	 *
	 * Unlike an enabling, a removal does not require the delivered system template to still be installed: it
	 * works by the explicit binding of the instance alone.
	 */
	public function disable(string $systemCode, SystemAiAgentContext $context): SystemAiAgentLifecycleResult
	{
		try
		{
			$identity = ManagedAgentIdentity::create($systemCode, $context);
		}
		catch (ArgumentException $exception)
		{
			return $this->rejectInput(self::OPERATION_DISABLE, $exception);
		}

		return $this->runUnderLock(
			self::OPERATION_DISABLE,
			$identity,
			self::FOREGROUND_LOCK_TIMEOUT_SECONDS,
			fn(): SystemAiAgentLifecycleResult => $this->runDisable($identity, respectWatchdog: false),
		);
	}

	/**
	 * Continues an unfinished removal of the given instance, which is what the background pass calls.
	 *
	 * The named lock is acquired without waiting, so a record that belongs to a running operation is skipped with
	 * an operation conflict and keeps its retry counter untouched. An instance that is still being created is
	 * taken over only after its watchdog deadline has passed.
	 */
	public function continueRemoval(ManagedAgentInstance $instance): SystemAiAgentLifecycleResult
	{
		$identity = self::buildIdentity($instance);
		if ($identity === null)
		{
			return $this->fail(
				self::OPERATION_DISABLE,
				null,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_IDENTITY_MISMATCH,
				instanceId: $instance->getId(),
			);
		}

		return $this->runUnderLock(
			self::OPERATION_DISABLE,
			$identity,
			self::BACKGROUND_LOCK_TIMEOUT_SECONDS,
			fn(): SystemAiAgentLifecycleResult => $this->runDisable($identity, respectWatchdog: true),
		);
	}

	/**
	 * Hands the managed instance over to a new owner: the stored owner of the instance and the signature of its
	 * configuration, which is derived from the owner, are brought in line with the copy in one step.
	 *
	 * Called inside the scope of {@see self::runOwnerTransferUnderLock()} and inside the transaction of the caller, in
	 * that order: the instance row is reread and held by this method, while the row of the copy is already held by
	 * the conditional update that changed its owner a moment earlier.
	 *
	 * The identity of the instance does not change - a transfer never moves the agent into another context - and
	 * a copy whose integrity was already broken before the transfer is refused instead of being silently repaired
	 * by a recalculated signature.
	 *
	 * @param int $newOwnerId owner the copy of the instance already carries
	 */
	public function transferOwner(ManagedAgentInstance $instance, int $newOwnerId): SystemAiAgentLifecycleResult
	{
		$identity = self::buildIdentity($instance);
		if ($identity === null)
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				null,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_IDENTITY_MISMATCH,
				instanceId: $instance->getId(),
			);
		}

		if ($this->instanceRepository === null)
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_REPOSITORY_UNAVAILABLE,
			);
		}

		if (!self::isLaunchingUserUsable($newOwnerId))
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::InvalidUser,
				self::CODE_USER_UNUSABLE,
				instanceId: $instance->getId(),
			);
		}

		try
		{
			// The instance was read before the lock was taken, so the state it is transferred from is the one the
			// row holds now; the locking reread also keeps that row until the transaction of the caller ends.
			$stored = $this->instanceRepository->lockById((int)$instance->getId());
		}
		catch (\Throwable $exception)
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_STORAGE_FAILED,
				instanceId: $instance->getId(),
				cause: $exception::class,
			);
		}

		if ($stored === null || !$identity->matchesStoredComponents($stored))
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::ConfigurationConflict,
				self::CODE_IDENTITY_MISMATCH,
				instanceId: $instance->getId(),
			);
		}

		if ($stored->getState() !== ManagedAgentInstanceState::Enabled)
		{
			// An instance that is still being created or is already being removed has no settled owner to hand
			// over, and a transfer would race the very pass that owns its state.
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::OperationConflict,
				$stored->getState() === ManagedAgentInstanceState::Enabling
					? self::CODE_ENABLING_IN_PROGRESS
					: self::CODE_CLEANUP_IN_PROGRESS,
				instanceId: $stored->getId(),
			);
		}

		return $this->reassignInstance($identity, $stored, $newOwnerId);
	}

	/**
	 * Verification of the copy against the stored instance and the write of the new owner into the instance.
	 *
	 * The new signature is calculated the very way the verification calculates the actual one, so a copy verified
	 * right after the transfer reproduces the signature that is stored here and {@see self::verifyCopy()} does not
	 * declare it broken.
	 */
	private function reassignInstance(
		ManagedAgentIdentity $identity,
		ManagedAgentInstance $instance,
		int $newOwnerId,
	): SystemAiAgentLifecycleResult
	{
		$copy = $this->readCopy($instance->getTemplateId());
		if ($copy === null)
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::ConfigurationConflict,
				self::CODE_COPY_ABSENT,
				instanceId: $instance->getId(),
			);
		}

		// The section descriptors of the source tell which template field declares which activation section, so
		// the signature cannot be recalculated without an available source, exactly as an enabling cannot.
		$source = $this->resolver->resolve($identity);
		if (!$source->isSuccess())
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::AgentNotAvailable,
				self::firstErrorCode($source),
				instanceId: $instance->getId(),
			);
		}

		$origin = self::readOriginSettings((int)$copy['ID']);

		// The copy already carries the new owner: the conditional update of the caller ran first, while the stored
		// signature of the instance still belongs to the previous one.
		$brokenInvariant = $this->verifyCopy(
			$identity,
			$instance,
			$copy,
			$source->getData(),
			$origin,
			copyOwnerId: $newOwnerId,
		);
		if ($brokenInvariant !== null)
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::ConfigurationConflict,
				$brokenInvariant,
				instanceId: $instance->getId(),
			);
		}

		$installedRevision = (string)$origin['version'];
		$fingerprint = self::calculateActualFingerprint(
			$identity,
			$newOwnerId,
			$installedRevision,
			self::describeCopy($copy, $source->getData(), $installedRevision),
		);
		if ($fingerprint === null)
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::ConfigurationConflict,
				self::CODE_CONFIGURATION_CHANGED,
				instanceId: $instance->getId(),
			);
		}

		try
		{
			$this->instanceRepository->save(ManagedAgentInstance::mapFromArray([
				...$instance->toArray(),
				'userId' => $newOwnerId,
				'configFingerprint' => $fingerprint,
				'updatedAt' => new DateTime(),
			]));
		}
		catch (\Throwable $exception)
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				$identity,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_STORAGE_FAILED,
				instanceId: $instance->getId(),
				cause: $exception::class,
			);
		}

		return $this->succeed(
			self::OPERATION_TRANSFER_OWNER,
			$identity,
			SystemAiAgentLifecycleOutcome::AlreadyEnabled,
			$instance->getId(),
			$newOwnerId,
		);
	}

	/**
	 * Runs $body under the named lock of the identity of $instance - the very lock every other operation of the
	 * lifecycle takes - so a caller never builds the lock name nor picks its timeout on its own.
	 *
	 * The lock is taken before $body runs and is released once it has returned, therefore a transaction belongs
	 * inside $body and never around this call: a lock released while writes are still uncommitted would let the
	 * background pass of the same instance in before the commit.
	 *
	 * @param callable(): Result $body
	 * @return Result the result of $body, or a failed result with a stable code of the lifecycle when the body
	 *  never ran at all: the lock was busy, the plumbing was unavailable or the pass itself threw
	 */
	public function runOwnerTransferUnderLock(ManagedAgentInstance $instance, callable $body): Result
	{
		$identity = self::buildIdentity($instance);
		if ($identity === null)
		{
			return $this->fail(
				self::OPERATION_TRANSFER_OWNER,
				null,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_IDENTITY_MISMATCH,
				instanceId: $instance->getId(),
			);
		}

		$passed = null;
		$scope = $this->runUnderLock(
			self::OPERATION_TRANSFER_OWNER,
			$identity,
			self::FOREGROUND_LOCK_TIMEOUT_SECONDS,
			function () use ($body, &$passed): SystemAiAgentLifecycleResult
			{
				$passed = $body();

				// The shared scope is typed on the result of the lifecycle and passes this value straight back,
				// while the answer of the caller is the result of the body and is returned below.
				return SystemAiAgentLifecycleResult::createSuccess(SystemAiAgentLifecycleOutcome::AlreadyEnabled);
			},
		);

		return $passed ?? $scope;
	}

	/**
	 * Takes the named lock of the identity, opens the operation scope of the registry and runs one bounded pass.
	 *
	 * The lock is released and the scope is closed in the same finally block, so neither of them can outlive the
	 * pass. An unexpected failure of the pass itself is answered as an infrastructure failure: every path that
	 * may leave a side effect behind compensates on its own before it returns.
	 *
	 * @param callable(): SystemAiAgentLifecycleResult $body
	 */
	private function runUnderLock(
		string $operation,
		ManagedAgentIdentity $identity,
		int $lockTimeoutSeconds,
		callable $body,
	): SystemAiAgentLifecycleResult
	{
		if ($this->instanceRepository === null || $this->resourceRepository === null)
		{
			return $this->fail(
				$operation,
				$identity,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_REPOSITORY_UNAVAILABLE,
			);
		}

		if ($this->registry === null)
		{
			return $this->fail(
				$operation,
				$identity,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_REGISTRY_UNAVAILABLE,
			);
		}

		$connection = Application::getConnection();
		$lockName = ManagedAgentResourceRegistry::buildLockName($identity);

		try
		{
			$locked = $connection->lock($lockName, $lockTimeoutSeconds);
		}
		catch (\Throwable)
		{
			// A lock that could not be requested is an infrastructure failure and not a busy lock.
			return $this->fail($operation, $identity, SystemAiAgentErrorCode::OperationFailed, self::CODE_LOCK_FAILED);
		}

		if (!$locked)
		{
			return $this->fail($operation, $identity, SystemAiAgentErrorCode::OperationConflict, self::CODE_LOCK_BUSY);
		}

		$this->registry->enterOperation($identity);

		try
		{
			return $body();
		}
		catch (\Throwable $exception)
		{
			// Only the class of the cause reaches the journal, exactly as a compensation reports one: an
			// unforeseen defect of the pass would otherwise arrive without a single sign to look it up by.
			return $this->fail(
				$operation,
				$identity,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_STORAGE_FAILED,
				cause: $exception::class,
			);
		}
		finally
		{
			$this->registry->leaveOperation($identity);

			// Releasing the lock is best effort: unlock() runs SQL of its own, and an exception thrown here
			// would replace the result of an operation that has already changed the state, so the caller would
			// read a failure after a completed enabling or removal and repeat it. A lock left behind is
			// released with the connection anyway.
			try
			{
				$connection->unlock($lockName);
			}
			catch (\Throwable)
			{
			}
		}
	}

	/**
	 * Body of the enabling algorithm under the lock: it parses the state of an existing instance, or resolves the
	 * source and creates a new one.
	 */
	private function runEnable(
		ManagedAgentIdentity $identity,
		int $userId,
		SystemAiAgentActivationParameters $activation,
	): SystemAiAgentLifecycleResult
	{
		$existing = $this->instanceRepository->findByIdentityHash($identity->getHash());
		if ($existing !== null)
		{
			return $this->continueExisting($identity, $userId, $activation, $existing);
		}

		// The source and its capability contract are read under the lock, so a source that disappears or gains an
		// unsupported producer meanwhile cannot slip in between the check and the copy.
		$source = $this->resolver->resolve($identity);
		if (!$source->isSuccess())
		{
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				SystemAiAgentErrorCode::AgentNotAvailable,
				self::firstErrorCode($source),
				userId: $userId,
			);
		}

		$sourceData = $source->getData();

		$capability = $this->capabilityValidator->validate($sourceData);
		if (!$capability->isSuccess())
		{
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				self::mapCapabilityFailure($capability),
				self::firstErrorCode($capability),
				userId: $userId,
			);
		}

		$configuration = $this->resolveConfiguration($identity, $userId, $activation, $sourceData);
		if (!$configuration->isSuccess())
		{
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				self::mapConfigurationFailure($configuration),
				self::firstErrorCode($configuration),
				userId: $userId,
			);
		}

		return $this->createInstance($identity, $userId, $activation, $sourceData, $configuration->getData());
	}

	/**
	 * Answer for an identity that already has an instance, by the state of that instance.
	 */
	private function continueExisting(
		ManagedAgentIdentity $identity,
		int $userId,
		SystemAiAgentActivationParameters $activation,
		ManagedAgentInstance $instance,
	): SystemAiAgentLifecycleResult
	{
		if (!$identity->matchesStoredComponents($instance))
		{
			// The hash matched while its stored components did not: the row belongs to another identity.
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				SystemAiAgentErrorCode::ConfigurationConflict,
				self::CODE_IDENTITY_MISMATCH,
				instanceId: $instance->getId(),
			);
		}

		return match ($instance->getState())
		{
			ManagedAgentInstanceState::Enabled => $this->confirmAlreadyEnabled(
				$identity,
				$userId,
				$activation,
				$instance,
			),
			ManagedAgentInstanceState::Enabling => self::isWatchdogExpired($instance)
				? $this->compensate(self::OPERATION_ENABLE, $identity, $instance, self::CODE_ENABLING_IN_PROGRESS)
				: $this->fail(
					self::OPERATION_ENABLE,
					$identity,
					SystemAiAgentErrorCode::OperationConflict,
					self::CODE_ENABLING_IN_PROGRESS,
					instanceId: $instance->getId(),
				),
			ManagedAgentInstanceState::Deleting,
			ManagedAgentInstanceState::CleanupPending => $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				SystemAiAgentErrorCode::CleanupPending,
				self::CODE_CLEANUP_IN_PROGRESS,
				outcome: SystemAiAgentLifecycleOutcome::CleanupPending,
				instanceId: $instance->getId(),
			),
		};
	}

	/**
	 * Confirms that the enabled instance still describes the requested activation, or names the conflict.
	 *
	 * The declarations of the managed copy are the schema of the check, therefore an update of the delivered
	 * source is not a conflict at all, while a manual change of the copy, of its effective configuration or of
	 * the launching user is one. A copy that is gone never answers "already enabled": the instance is moved into
	 * removal instead.
	 */
	private function confirmAlreadyEnabled(
		ManagedAgentIdentity $identity,
		int $userId,
		SystemAiAgentActivationParameters $activation,
		ManagedAgentInstance $instance,
	): SystemAiAgentLifecycleResult
	{
		$copy = $this->readCopy($instance->getTemplateId());
		if ($copy === null)
		{
			return $this->compensate(self::OPERATION_ENABLE, $identity, $instance, self::CODE_COPY_ABSENT);
		}

		// An enabling always requires an available system source, even when nothing has to be created: the
		// section descriptors of the source tell which template field declares which activation section.
		$source = $this->resolver->resolve($identity);
		if (!$source->isSuccess())
		{
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				SystemAiAgentErrorCode::AgentNotAvailable,
				self::firstErrorCode($source),
				instanceId: $instance->getId(),
			);
		}

		$origin = self::readOriginSettings((int)$copy['ID']);

		$brokenInvariant = $this->verifyCopy($identity, $instance, $copy, $source->getData(), $origin);
		if ($brokenInvariant !== null)
		{
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				SystemAiAgentErrorCode::ConfigurationConflict,
				$brokenInvariant,
				instanceId: $instance->getId(),
			);
		}

		$requested = $this->configurationResolver->resolve(
			$identity,
			$userId,
			$activation,
			self::describeCopy($copy, $source->getData(), (string)$origin['version']),
		);
		if (!$requested->isSuccess())
		{
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				self::mapConfigurationFailure($requested),
				self::firstErrorCode($requested),
				instanceId: $instance->getId(),
				userId: $userId,
			);
		}

		$fingerprint = $requested->getData()[SystemAiAgentConfigurationResolver::DATA_CONFIGURATION];
		if (!$fingerprint->matches($instance->getConfigFingerprint()))
		{
			return $this->fail(
				self::OPERATION_ENABLE,
				$identity,
				SystemAiAgentErrorCode::ConfigurationConflict,
				self::CODE_CONFIGURATION_CHANGED,
				instanceId: $instance->getId(),
			);
		}

		return $this->succeed(
			self::OPERATION_ENABLE,
			$identity,
			SystemAiAgentLifecycleOutcome::AlreadyEnabled,
			$instance->getId(),
			$instance->getUserId(),
		);
	}

	/**
	 * Creates the instance, its copy and the resources of the first start, and publishes the result.
	 *
	 * A unique key violation is never answered by a blind repeated insert: the stored row is reread, its identity
	 * components are compared strictly and the same state parsing is applied to it.
	 */
	private function createInstance(
		ManagedAgentIdentity $identity,
		int $userId,
		SystemAiAgentActivationParameters $activation,
		array $source,
		array $configuration,
	): SystemAiAgentLifecycleResult
	{
		try
		{
			$instance = $this->instanceRepository->save(self::describeNewInstance(
				$identity,
				$userId,
				$configuration[SystemAiAgentConfigurationResolver::DATA_CONFIGURATION]->getFingerprint(),
			));
		}
		catch (\Throwable)
		{
			$stored = $this->instanceRepository->findByIdentityHash($identity->getHash());
			if ($stored === null)
			{
				return $this->fail(
					self::OPERATION_ENABLE,
					$identity,
					SystemAiAgentErrorCode::OperationFailed,
					self::CODE_STORAGE_FAILED,
					userId: $userId,
				);
			}

			return $this->continueExisting($identity, $userId, $activation, $stored);
		}

		try
		{
			$instance = $this->prepareCopy($identity, $instance, $source, $configuration);
		}
		catch (\Throwable $exception)
		{
			return $this->compensate(
				self::OPERATION_ENABLE,
				$identity,
				$instance,
				self::CODE_COPY_FAILED,
				$exception,
			);
		}

		$failure = $this->launchCopy($identity, $instance, $configuration);
		if ($failure !== null)
		{
			return $this->compensate(self::OPERATION_ENABLE, $identity, $instance, $failure);
		}

		$brokenInvariant = null;

		$failure = $this->publish($identity, $instance, $source, $brokenInvariant);
		if ($failure !== null)
		{
			return $this->compensate(self::OPERATION_ENABLE, $identity, $instance, $failure, $brokenInvariant);
		}

		return $this->succeed(
			self::OPERATION_ENABLE,
			$identity,
			SystemAiAgentLifecycleOutcome::Enabled,
			$instance->getId(),
			$instance->getUserId(),
		);
	}

	/**
	 * One short transaction before the first start: the copy, its prepared parameters and constants, the template
	 * binding, the storage scope, the reservations of the absent bot codes and the temporary suppression of the
	 * schedules.
	 *
	 * @return ManagedAgentInstance the instance with the binding of its copy
	 * @throws \Throwable when a step of the preparation fails, which the caller compensates
	 */
	private function prepareCopy(
		ManagedAgentIdentity $identity,
		ManagedAgentInstance $instance,
		array $source,
		array $configuration,
	): ManagedAgentInstance
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$copyResult = $this->activationService->copyTemplate(
				(int)$source[SystemAiAgentResolver::DATA_TEMPLATE_ID],
				$instance->getUserId(),
			);
			if (!$copyResult instanceof TemplateCreatedResult || $copyResult->templateId <= 0)
			{
				throw new \RuntimeException('A managed copy of the system AI agent was not created');
			}

			$templateId = $copyResult->templateId;

			// The binding is written before any further step, so every producer of the copy is classified as
			// managed and the resources it creates belong to this instance.
			$instance = $this->instanceRepository->save($instance->withTemplateId($templateId, new DateTime()));

			// A producer that ran while the copy was still unbound has already classified it as unmanaged for the
			// rest of the request, so that answer is dropped before the barrier may need it.
			$this->registry->forgetClassification($templateId);

			$this->assertSameSnapshot($identity, $templateId, $source);

			\CBPWorkflowTemplateLoader::update(
				$templateId,
				$configuration[SystemAiAgentConfigurationResolver::DATA_FIELDS],
				systemImport: true,
			);

			$this->registerResource($templateId, ManagedAgentResourceType::StorageScope, (string)$templateId);
			$this->reserveBotCodes($templateId);

			// The schedules of the copy are published only together with the enabled state, so a scheduled
			// trigger cannot start a workflow of an instance that is not finished yet.
			$this->suppressSchedules($templateId);

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollbackQuietly($connection);

			throw $exception;
		}

		return $instance;
	}

	/**
	 * Starts the copy from the explicit user, continues its setup block and takes ownership of everything the
	 * start created.
	 *
	 * No transaction spans this method on purpose: a workflow start is an external side effect that cannot be
	 * rolled back with a database transaction and is compensated by the removal instead.
	 *
	 * @return string|null stable internal code of the failure, or null when the copy runs and is set up
	 */
	private function launchCopy(
		ManagedAgentIdentity $identity,
		ManagedAgentInstance $instance,
		array $configuration,
	): ?string
	{
		$templateId = (int)$instance->getTemplateId();

		try
		{
			$startResult = $this->startWithOperationMarker($identity, $templateId, $instance->getUserId());
		}
		catch (\Throwable)
		{
			return self::CODE_START_FAILED;
		}

		if (!$startResult->isSuccess())
		{
			return self::CODE_START_FAILED;
		}

		$setupEvent = $startResult->setupTemplateEvent;
		$setupInstanceId = $setupEvent?->getInstanceId();
		if ($setupEvent !== null && $setupInstanceId !== null && $setupInstanceId !== '')
		{
			$values = self::selectSetupConstants(
				$configuration[SystemAiAgentConfigurationResolver::DATA_CONFIGURATION]
					->getSection(SystemAiAgentActivationParameters::SECTION_CONSTANTS),
				$setupEvent->getBlocks(),
			);

			// The trusted access mode is chosen by the service and never comes from a public DTO: the values are
			// the already validated subset of the constants of the setup blocks.
			$fillResult = $this->setupTemplateService->fill(
				$instance->getUserId(),
				$templateId,
				$setupInstanceId,
				$values,
				skipAccessValidation: true,
				applyDefaults: true,
			);
			if (!$fillResult->isSuccess())
			{
				return self::CODE_SETUP_FAILED;
			}
		}

		// Reconciliation closes the window between the creation of a resource during the start and its
		// registration, and completes the reservations of the bots with their actual ids.
		//
		// A bounded portion that ran out of its budget is not a refusal of the ownership: every reconciliation is
		// an idempotent adoption by the template of the copy, and the removal repeats it for every type before it
		// deletes anything, so a pending answer costs a later adoption and never the copy itself. Only a failed
		// one - an unsupported type, an unreadable owner, a participant that threw - compensates the enabling.
		return $this->reconcileAll($instance, $this->createBudget())->isFailed()
			? self::CODE_RESOURCE_OWNERSHIP_FAILED
			: null
		;
	}

	/**
	 * Publishes the schedules of the copy and fixes the enabled state in one final transaction.
	 *
	 * The transaction locks the row of the copy and the rows of its settings and verifies the origin, the
	 * installed revision, the declarations and the actual configuration against the stored signature again, so a
	 * copy that was changed while the workflow was starting never reaches the enabled state.
	 *
	 * @param string|null $brokenInvariant filled with the stable internal code of the invariant of the copy the
	 *  publication was refused for, when that is the reason; it is a name of the code and carries no value, so
	 *  the compensation is able to hand it to the journal
	 * @return string|null stable internal code of the failure, or null when the instance is enabled
	 */
	private function publish(
		ManagedAgentIdentity $identity,
		ManagedAgentInstance $instance,
		array $source,
		?string &$brokenInvariant = null,
	): ?string
	{
		$instanceId = (int)$instance->getId();
		$templateId = (int)$instance->getTemplateId();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$locked = $this->instanceRepository->lockById($instanceId);
			if ($locked === null || $locked->getState() !== ManagedAgentInstanceState::Enabling)
			{
				throw new \RuntimeException('The managed instance is no longer being enabled');
			}

			self::lockCopyRows($templateId);

			$copy = $this->readCopy($templateId);
			if ($copy === null)
			{
				throw new \RuntimeException('The managed copy is gone');
			}

			$brokenInvariant = $this->verifyCopy(
				$identity,
				$locked,
				$copy,
				$source,
				self::readOriginSettings($templateId),
			);
			if ($brokenInvariant !== null)
			{
				throw new \RuntimeException('The managed copy does not match its signature: ' . $brokenInvariant);
			}

			$this->publishSchedules($templateId);

			// The transition also clears the watchdog deadline, the retry counter and the last error code.
			if (!$this->instanceRepository->markEnabled($instanceId, new DateTime()))
			{
				throw new \RuntimeException('The enabled state of the managed instance was not fixed');
			}

			$connection->commitTransaction();
		}
		catch (\Throwable)
		{
			self::rollbackQuietly($connection);

			return self::CODE_PUBLISH_FAILED;
		}

		return null;
	}

	/**
	 * Moves the instance into removal and runs the body of the removal algorithm, which is the compensation of an
	 * enabling that failed after its copy appeared.
	 *
	 * A final success of the enabling is never returned from here: an unfinished compensation answers with the
	 * pending cleanup, and a finished one still answers that the enabling failed.
	 *
	 * @param \Throwable|string|null $cause exception the failed step was refused with, or the stable internal code
	 *  of the invariant it broke, when the step had one of them; of an exception only its class reaches the
	 *  journal, so the diagnostics of a step that threw is not lost with the exception itself
	 */
	private function compensate(
		string $operation,
		ManagedAgentIdentity $identity,
		ManagedAgentInstance $instance,
		string $internalCode,
		\Throwable|string|null $cause = null,
	): SystemAiAgentLifecycleResult
	{
		$causeName = $cause instanceof \Throwable ? $cause::class : $cause;

		$removal = $this->runRemoval($operation, $identity, $instance, journaled: false);
		if ($removal->getOutcome() === SystemAiAgentLifecycleOutcome::CleanupPending)
		{
			return $this->fail(
				$operation,
				$identity,
				SystemAiAgentErrorCode::CleanupPending,
				$internalCode,
				outcome: SystemAiAgentLifecycleOutcome::CleanupPending,
				instanceId: $instance->getId(),
				cause: $causeName,
			);
		}

		return $this->fail(
			$operation,
			$identity,
			SystemAiAgentErrorCode::EnableFailed,
			$internalCode,
			instanceId: $instance->getId(),
			cause: $causeName,
		);
	}

	/**
	 * Body of the removal algorithm under the lock: it finds the binding and runs one bounded removal pass.
	 */
	private function runDisable(ManagedAgentIdentity $identity, bool $respectWatchdog): SystemAiAgentLifecycleResult
	{
		$instance = $this->instanceRepository->findByIdentityHash($identity->getHash());
		if ($instance === null)
		{
			return $this->succeed(self::OPERATION_DISABLE, $identity, SystemAiAgentLifecycleOutcome::AlreadyDisabled);
		}

		if (!$identity->matchesStoredComponents($instance))
		{
			return $this->fail(
				self::OPERATION_DISABLE,
				$identity,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_IDENTITY_MISMATCH,
				instanceId: $instance->getId(),
			);
		}

		if (
			$respectWatchdog
			&& $instance->getState() === ManagedAgentInstanceState::Enabling
			&& !self::isWatchdogExpired($instance)
		)
		{
			// The record belongs to an enabling that is still inside its watchdog deadline, so it is skipped
			// without charging a retry.
			return $this->fail(
				self::OPERATION_DISABLE,
				$identity,
				SystemAiAgentErrorCode::OperationConflict,
				self::CODE_ENABLING_IN_PROGRESS,
				instanceId: $instance->getId(),
			);
		}

		return $this->runRemoval(self::OPERATION_DISABLE, $identity, $instance, journaled: true);
	}

	/**
	 * One bounded pass of the complete removal: the copy stops producing work, every resource type is reconciled
	 * and cleaned up in the order of {@see ManagedAgentResourceType::cleanupOrder()}, the copy is deleted and the
	 * service link is released last.
	 *
	 * @param bool $journaled whether the outcome of the pass is a terminal record of the journal; a compensation
	 *  of an enabling reports its own outcome instead
	 */
	private function runRemoval(
		string $operation,
		ManagedAgentIdentity $identity,
		ManagedAgentInstance $instance,
		bool $journaled,
	): SystemAiAgentLifecycleResult
	{
		$instanceId = (int)$instance->getId();

		try
		{
			$moved = $this->instanceRepository->compareAndSetState(
				$instanceId,
				[
					ManagedAgentInstanceState::Enabled,
					ManagedAgentInstanceState::Enabling,
					ManagedAgentInstanceState::CleanupPending,
					ManagedAgentInstanceState::Deleting,
				],
				ManagedAgentInstanceState::Deleting,
				new DateTime(),
			);
		}
		catch (\Throwable)
		{
			$moved = false;
		}

		if (!$moved)
		{
			return $this->terminal(
				$operation,
				$identity,
				$journaled,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_STORAGE_FAILED,
				null,
				$instanceId,
			);
		}

		// The reread instance carries the deleting state the participants expect, and the closed barrier of the
		// registry now refuses every direct creation of a resource of this instance.
		$instance = $this->instanceRepository->getById($instanceId) ?? $instance;
		$templateId = $instance->getTemplateId();

		$this->deactivateCopy($templateId);

		$budget = $this->createBudget();

		foreach (ManagedAgentResourceType::cleanupOrder() as $type)
		{
			$result = $this->cleanupResourceType($type, $instance, $budget);
			if (!$result->isComplete())
			{
				return $this->continueLater($operation, $identity, $instance, $result, $journaled);
			}

			if ($type === ManagedAgentResourceType::Schedule && $templateId !== null)
			{
				// The participant deletes schedule rows directly, so the standard agent of the scheduled
				// triggers is resynchronized once after the whole type is done.
				$this->suppressSchedules($templateId);
			}
		}

		if ($templateId !== null && !$this->deleteCopy($templateId))
		{
			return $this->continueLater(
				$operation,
				$identity,
				$instance,
				ManagedResourceCleanupResult::createFailed(self::CODE_TEMPLATE_STILL_PRESENT),
				$journaled,
			);
		}

		$reconciled = $this->reconcileAll($instance, $budget);
		if (!$reconciled->isComplete())
		{
			return $this->continueLater($operation, $identity, $instance, $reconciled, $journaled);
		}

		$failure = $this->releaseInstanceLink($instance);
		if ($failure !== null)
		{
			return $this->continueLater(
				$operation,
				$identity,
				$instance,
				ManagedResourceCleanupResult::createFailed($failure),
				$journaled,
			);
		}

		return $journaled
			? $this->succeed($operation, $identity, SystemAiAgentLifecycleOutcome::Disabled, $instanceId)
			: SystemAiAgentLifecycleResult::createSuccess(SystemAiAgentLifecycleOutcome::Disabled)
		;
	}

	/**
	 * Reconciles the registered resources of one type and cleans up every registered row of it.
	 *
	 * The remaining budget is checked before a participant is called, so a pass that is already exhausted reports
	 * a regular continuation instead of asking a participant for an answer it has no room to give.
	 *
	 * The reconciliation runs inside the phase of its own, whose share of the budget it cannot exceed, and a
	 * reconciliation that did not finish does not stop the deletion of the rows that are already registered. Both
	 * of that is what makes repeated passes converge: a reconciliation is proportional to the resources that are
	 * still live, so a pass that let it hold the deletion back would never shrink the very set it walks and the
	 * instance would stay in the pending cleanup for good. Completeness is not weakened by it - the outcome of
	 * such a type stays pending, therefore the removal never reports a final success while a resource is left.
	 */
	private function cleanupResourceType(
		ManagedAgentResourceType $type,
		ManagedAgentInstance $instance,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		$participant = $this->cleanupParticipants[$type->value] ?? null;
		if ($participant === null)
		{
			return ManagedResourceCleanupResult::createFailed(self::CODE_PARTICIPANT_MISSING);
		}

		if ($budget->isExhausted())
		{
			return ManagedResourceCleanupResult::createPending();
		}

		$instanceId = (int)$instance->getId();

		$budget->enterReconcilePhase();
		$reconciled = self::runParticipant(
			static fn(): ManagedResourceCleanupResult => $participant->reconcile($instance, $budget),
		);
		$budget->enterCleanupPhase();

		if ($reconciled->isFailed())
		{
			return $reconciled;
		}

		while (true)
		{
			if ($budget->isExhausted())
			{
				return ManagedResourceCleanupResult::createPending();
			}

			try
			{
				// Every complete row is deleted below, therefore the next portion starts from the beginning of
				// the same key and the pass always makes progress.
				$resources = $this->resourceRepository->findBatchByType(
					$instanceId,
					$type,
					min($budget->getRemainingRows(), self::REGISTRY_BATCH_LIMIT),
				);
			}
			catch (\Throwable)
			{
				return ManagedResourceCleanupResult::createFailed(self::CODE_STORAGE_FAILED);
			}

			$budget->consumeRows(count($resources));

			if ($resources === [])
			{
				break;
			}

			foreach ($resources as $resource)
			{
				if ($budget->isExhausted())
				{
					return ManagedResourceCleanupResult::createPending();
				}

				$result = self::runParticipant(
					static fn(): ManagedResourceCleanupResult => $participant->cleanup($resource, $budget),
				);
				if (!$result->isComplete())
				{
					return $result;
				}

				try
				{
					// The service row is dropped only after a complete result: while a cleanup is pending, the
					// row is the only proof of ownership the next pass is left with.
					$this->resourceRepository->delete((int)$resource->getId());
				}
				catch (\Throwable)
				{
					return ManagedResourceCleanupResult::createFailed(self::CODE_STORAGE_FAILED);
				}
			}
		}

		try
		{
			$remain = $this->resourceRepository->hasResourcesOfType($instanceId, $type);
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::CODE_STORAGE_FAILED);
		}

		if ($remain)
		{
			// The drain of the type answered with an empty portion while its registry is not empty, so the row
			// it left behind was written by somebody else and is not a portion of work of this pass.
			return ManagedResourceCleanupResult::createBlocked(self::CODE_RESOURCES_REMAIN);
		}

		// An empty registry of the type is not the completion criterion while its reconciliation is unfinished:
		// a live resource it has not walked yet carries no row of its own to be found by. Its result is carried
		// as it is, so a continuation that waits for an external dependency keeps the retry delay of its own.
		return $reconciled->isComplete() ? ManagedResourceCleanupResult::createComplete() : $reconciled;
	}

	/**
	 * Reconciles every resource type of the instance, which closes the window between the creation of a resource
	 * and its registration: after the first start of the copy and again under the closed creation barrier, before
	 * the service link is released.
	 */
	private function reconcileAll(
		ManagedAgentInstance $instance,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult
	{
		foreach (ManagedAgentResourceType::cleanupOrder() as $type)
		{
			$participant = $this->cleanupParticipants[$type->value] ?? null;
			if ($participant === null)
			{
				return ManagedResourceCleanupResult::createFailed(self::CODE_PARTICIPANT_MISSING);
			}

			if ($budget->isExhausted())
			{
				return ManagedResourceCleanupResult::createPending();
			}

			$result = self::runParticipant(
				static fn(): ManagedResourceCleanupResult => $participant->reconcile($instance, $budget),
			);
			if (!$result->isComplete())
			{
				return $result;
			}
		}

		return ManagedResourceCleanupResult::createComplete();
	}

	/**
	 * Stops the copy from producing new work: an inactive template keeps neither a trigger nor a schedule row, so
	 * no later resource type can gain work that was created after its own pass.
	 */
	private function deactivateCopy(?int $templateId): void
	{
		if ($templateId === null || $templateId <= 0)
		{
			return;
		}

		try
		{
			$row = WorkflowTemplateTable::query()
				->setSelect(['ACTIVE'])
				->where('ID', $templateId)
				->setLimit(1)
				->fetch()
			;

			if (is_array($row) && $row['ACTIVE'] !== 'N')
			{
				\CBPWorkflowTemplateLoader::update($templateId, ['ACTIVE' => 'N'], systemImport: true);
			}
		}
		catch (\Throwable)
		{
			// A deactivation that did not happen is repeated by the next pass; the barrier of the registry
			// already refuses every direct creation of a resource of this instance.
		}
	}

	/**
	 * Deletes the copy of the instance and confirms that it is really gone.
	 */
	private function deleteCopy(int $templateId): bool
	{
		try
		{
			\CBPWorkflowTemplateLoader::delete($templateId);

			$row = WorkflowTemplateTable::query()
				->setSelect(['ID'])
				->where('ID', $templateId)
				->setLimit(1)
				->fetch()
			;
		}
		catch (\Throwable)
		{
			return false;
		}

		return !is_array($row);
	}

	/**
	 * Releases the service link of the instance in one transaction: the row is locked, the registry is required
	 * to be empty and only then the link is deleted.
	 *
	 * The creation barrier uses the same logical lock this pass holds, therefore no resource can appear between
	 * the final reconciliation and the removal of the link.
	 *
	 * A resource left behind is a regular outcome and not a failure, so that branch closes the transaction instead
	 * of rolling it back: it has written nothing, the lock of the row is a reading one, and the command is public,
	 * so a rollback of a nested transaction would end the transaction of the caller as well.
	 *
	 * @return string|null stable internal code of the failure, or null when the link is gone
	 */
	private function releaseInstanceLink(ManagedAgentInstance $instance): ?string
	{
		$instanceId = (int)$instance->getId();

		$connection = Application::getConnection();
		$connection->startTransaction();

		$resourcesRemain = false;

		try
		{
			$locked = $this->instanceRepository->lockById($instanceId);
			if ($locked === null)
			{
				$connection->commitTransaction();

				return null;
			}

			$resourcesRemain = $this->resourceRepository->hasResources($instanceId);
			if (!$resourcesRemain)
			{
				$this->instanceRepository->delete($instanceId);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable)
		{
			self::rollbackQuietly($connection);

			return self::CODE_STORAGE_FAILED;
		}

		return $resourcesRemain ? self::CODE_RESOURCES_REMAIN : null;
	}

	/**
	 * Saves the continuation of an unfinished pass and answers with the pending cleanup.
	 *
	 * A regular continuation of a bounded portion is assigned to the next run of the background pass and resets
	 * the sequence of errors: the pass really did a part of the work and the next one meets less of it.
	 *
	 * An outcome that {@see ManagedResourceCleanupResult::chargesRetryDelay()} is given the backoff instead, and
	 * only a failure stores the reason as the last error of the record, which is what tells the two apart.
	 *
	 * The pending cleanup is answered only after the state and the retry deadline were stored and reread
	 * successfully; a continuation whose durability is not confirmed is answered as a failed operation instead.
	 */
	private function continueLater(
		string $operation,
		ManagedAgentIdentity $identity,
		ManagedAgentInstance $instance,
		ManagedResourceCleanupResult $result,
		bool $journaled,
	): SystemAiAgentLifecycleResult
	{
		$instanceId = (int)$instance->getId();
		$failed = $result->isFailed();
		$charged = $result->chargesRetryDelay();
		$retryCount = $charged ? $instance->getRetryCount() + 1 : 0;
		$nextRetryAt = $charged ? self::describeRetryDeadline($retryCount) : null;
		$reason = $result->getCode();

		try
		{
			$this->instanceRepository->save(
				$instance
					->withState(ManagedAgentInstanceState::CleanupPending, new DateTime())
					->withRetry($retryCount, $nextRetryAt, $failed ? $reason : null, new DateTime()),
			);

			$stored = $this->instanceRepository->getById($instanceId);
		}
		catch (\Throwable)
		{
			$stored = null;
		}

		$durable =
			$stored !== null
			&& $stored->getState() === ManagedAgentInstanceState::CleanupPending
			&& ($stored->getNextRetryAt() === null) === ($nextRetryAt === null)
		;

		if (!$durable)
		{
			return $this->terminal(
				$operation,
				$identity,
				$journaled,
				SystemAiAgentErrorCode::OperationFailed,
				self::CODE_CONTINUATION_UNCONFIRMED,
				null,
				$instanceId,
			);
		}

		return $this->terminal(
			$operation,
			$identity,
			$journaled,
			SystemAiAgentErrorCode::CleanupPending,
			$reason,
			SystemAiAgentLifecycleOutcome::CleanupPending,
			$instanceId,
		);
	}

	/**
	 * Starts the copy with the one time marker of the current operation in its start parameters.
	 *
	 * The marker authorizes exactly one start while the instance is still being created. It travels as a start
	 * parameter of the template, so the barrier of the registry finds it among the top level parameters of the
	 * workflow and consumes it there before the workflow is initialized: the marker is never persisted.
	 */
	private function startWithOperationMarker(
		ManagedAgentIdentity $identity,
		int $templateId,
		int $userId,
	): AiAgentStartResult
	{
		$marker = $this->registry->issueStartMarker($identity);

		return $this->activationService->startTemplate(
			$templateId,
			$userId,
			startParameters: [ManagedAgentResourceRegistry::PARAMETER_OPERATION_MARKER => $marker],
		);
	}

	/**
	 * Reserves the codes of the bots the copy is going to create.
	 *
	 * A code that already holds a bot is not reserved: such a bot is an external dependency of the agent and is
	 * never owned or deleted by this API. The codes themselves come from the standard extractor, which resolves
	 * the reference to the copy of the template.
	 *
	 * @throws \RuntimeException when a bot is declared while its module is unavailable, or a reservation is
	 *  refused by the barrier
	 */
	private function reserveBotCodes(int $templateId): void
	{
		$codes = $this->chatbotsExtractor->extractBotCodes([$templateId]);

		$types = [
			AgentChatbotsExtractor::KIND_BIZPROC => [ManagedAgentResourceType::BizprocBot, BizprocBot::class],
			AgentChatbotsExtractor::KIND_OPENLINES => [
				ManagedAgentResourceType::OpenLinesBot,
				AgentChatbotsExtractor::OPENLINES_BOT_CLASS,
			],
		];

		$declared = array_merge(
			$codes[AgentChatbotsExtractor::KIND_BIZPROC],
			$codes[AgentChatbotsExtractor::KIND_OPENLINES],
		);
		if ($declared === [])
		{
			return;
		}

		// Asked only when the graph really produces a bot: an agent without bots must not depend on the module.
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			throw new \RuntimeException(self::CODE_BOT_MODULE_MISSING);
		}

		foreach ($types as $kind => [$type, $botClass])
		{
			$existing = self::readExistingBotCodes($botClass, $codes[$kind]);

			foreach ($codes[$kind] as $code)
			{
				if (!isset($existing[$code]))
				{
					$this->registerResource($templateId, $type, $code);
				}
			}
		}
	}

	/**
	 * Registers a resource of the copy through the barrier of the registry.
	 *
	 * @throws \RuntimeException when the barrier refuses the registration, which compensates the enabling
	 */
	private function registerResource(int $templateId, ManagedAgentResourceType $type, string $resourceId): void
	{
		$result = $this->registry->register($templateId, $type, $resourceId);
		if (!$result->isSuccess())
		{
			throw new \RuntimeException(self::CODE_RESOURCE_OWNERSHIP_FAILED . ': ' . self::firstErrorCode($result));
		}
	}

	/**
	 * Confirms that the origin of the copy, the fields of its activation sections and the content that was really
	 * copied belong to one snapshot of the source.
	 *
	 * @throws \RuntimeException when the snapshot of the copy differs from the resolved one
	 */
	private function assertSameSnapshot(ManagedAgentIdentity $identity, int $templateId, array $source): void
	{
		$copy = $this->readCopy($templateId);
		if ($copy === null)
		{
			throw new \RuntimeException('The managed copy cannot be read');
		}

		$origin = self::readOriginSettings($templateId);
		$installedRevision = (string)$source[SystemAiAgentResolver::DATA_INSTALLED_REVISION];

		if (
			$origin['codeCount'] !== 1
			|| $origin['code'] !== $identity->getSystemCode()
			|| $origin['versionCount'] !== 1
			|| $origin['version'] !== $installedRevision
			|| $this->revisionService->calculateRevision((array)$copy['TEMPLATE']) !== $installedRevision
		)
		{
			throw new \RuntimeException('The managed copy does not belong to the resolved source snapshot');
		}
	}

	/**
	 * Checks the ownership and lifecycle invariants of the managed copy and its actual configuration against the
	 * stored signature of the instance.
	 *
	 * @param array{code: ?string, codeCount: int, version: ?string, versionCount: int} $origin
	 * @param int|null $copyOwnerId owner the row of the copy is expected to carry, the stored owner of the instance
	 *  by default: a transfer has already written the new owner into that row while the stored signature of the
	 *  instance still belongs to the previous one
	 * @return string|null stable internal code of the broken invariant, or null when the copy is intact
	 */
	private function verifyCopy(
		ManagedAgentIdentity $identity,
		ManagedAgentInstance $instance,
		array $copy,
		array $source,
		array $origin,
		?int $copyOwnerId = null,
	): ?string
	{
		$copyOwnerId ??= $instance->getUserId();

		if (
			$copy['TYPE'] !== WorkflowTemplateType::Nodes->value
			|| (string)($copy['SYSTEM_CODE'] ?? '') !== ''
			|| $copy['ACTIVE'] !== 'Y'
			|| (int)($copy['ACTIVATED_BY'] ?? 0) !== $copyOwnerId
			|| $copy['ACTIVATED_AT'] === null
			|| $origin['codeCount'] !== 1
			|| $origin['code'] !== $identity->getSystemCode()
			|| $origin['versionCount'] !== 1
			|| $origin['version'] === null
		)
		{
			return self::CODE_COPY_INVARIANT_BROKEN;
		}

		$installedRevision = (string)$origin['version'];
		if ($this->revisionService->calculateRevision((array)$copy['TEMPLATE']) !== $installedRevision)
		{
			return self::CODE_COPY_CUSTOMIZED;
		}

		$actual = self::calculateActualFingerprint(
			$identity,
			$instance->getUserId(),
			$installedRevision,
			self::describeCopy($copy, $source, $installedRevision),
		);

		if ($actual === null || !hash_equals($instance->getConfigFingerprint(), $actual))
		{
			return self::CODE_CONFIGURATION_CHANGED;
		}

		return null;
	}

	/**
	 * Effective configuration of the activation, built against the declarations that are really written into the
	 * copy.
	 *
	 * The first pass validates the values of the caller against the declarations of the source and produces the
	 * fields of the copy; the second pass repeats the very same validation against those fields. The stored
	 * signature is therefore the one a repeated activation of the same values reproduces from the copy alone,
	 * which is what {@see self::verifyCopy()} relies on.
	 */
	private function resolveConfiguration(
		ManagedAgentIdentity $identity,
		int $userId,
		SystemAiAgentActivationParameters $activation,
		array $source,
	): Result
	{
		$resolved = $this->configurationResolver->resolve($identity, $userId, $activation, $source);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}

		return $this->configurationResolver->resolve(
			$identity,
			$userId,
			$activation,
			self::withSections(
				$source,
				self::describeSections($source, $resolved->getData()[SystemAiAgentConfigurationResolver::DATA_FIELDS]),
			),
		);
	}

	/**
	 * Temporarily removes every schedule row of the copy and resynchronizes the standard agent of the scheduled
	 * triggers, without touching the trigger rows the start of the copy needs.
	 */
	private function suppressSchedules(int $templateId): void
	{
		$this->scheduleSyncService->syncByTemplate($templateId, null, false);
	}

	private function publishSchedules(int $templateId): void
	{
		$this->scheduleSyncService->syncByTemplate($templateId, null, true);
	}

	private function createBudget(): ManagedResourceCleanupBudget
	{
		return new ManagedResourceCleanupBudget($this->cleanupRowLimit, $this->cleanupPassSeconds);
	}

	/**
	 * Row of the managed copy, or null when it is gone.
	 */
	private function readCopy(?int $templateId): ?array
	{
		if ($templateId === null || $templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(self::COPY_SELECT)
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $row;
	}

	/**
	 * Source shaped descriptor of the copy: the sections of the source with the declarations of the copy and the
	 * installed revision of the copy, so neither fingerprint depends on a source that may already carry a newer
	 * revision.
	 */
	private static function describeCopy(array $copy, array $source, string $installedRevision): array
	{
		$fields = [];
		foreach ($source[SystemAiAgentResolver::DATA_SECTIONS] as $section)
		{
			$field = $section[SystemAiAgentResolver::SECTION_FIELD];
			$fields[$field] = is_array($copy[$field] ?? null) ? $copy[$field] : [];
		}

		$descriptor = self::withSections($source, self::describeSections($source, $fields));
		$descriptor[SystemAiAgentResolver::DATA_INSTALLED_REVISION] = $installedRevision;
		$descriptor[SystemAiAgentResolver::DATA_DOCUMENT_TYPE] = [
			(string)$copy['MODULE_ID'],
			(string)$copy['ENTITY'],
			(string)$copy['DOCUMENT_TYPE'],
		];

		return $descriptor;
	}

	/**
	 * Sections of the source with the declarations taken from the given serialized template fields.
	 *
	 * @param array<string, array> $fields payload of every serialized template field
	 * @return array<string, array>
	 */
	private static function describeSections(array $source, array $fields): array
	{
		$sections = [];
		foreach ($source[SystemAiAgentResolver::DATA_SECTIONS] as $name => $section)
		{
			$field = $section[SystemAiAgentResolver::SECTION_FIELD];
			$declarations = is_array($fields[$field] ?? null) ? $fields[$field] : [];

			$sections[$name] = array_merge($section, [SystemAiAgentResolver::SECTION_DECLARATIONS => $declarations]);
		}

		return $sections;
	}

	private static function withSections(array $source, array $sections): array
	{
		$source[SystemAiAgentResolver::DATA_SECTIONS] = $sections;

		return $source;
	}

	/**
	 * Fingerprint of the configuration the copy actually carries, or null when its values cannot be canonicalized
	 * at all, which is a conflict as well.
	 */
	private static function calculateActualFingerprint(
		ManagedAgentIdentity $identity,
		int $userId,
		string $installedRevision,
		array $descriptor,
	): ?string
	{
		$declarations = [];
		$values = [];

		foreach ($descriptor[SystemAiAgentResolver::DATA_SECTIONS] as $name => $section)
		{
			$sectionDeclarations = $section[SystemAiAgentResolver::SECTION_DECLARATIONS];

			$declarations[$name] = $sectionDeclarations;
			$values[$name] = [];

			foreach ($sectionDeclarations as $declarationName => $declaration)
			{
				$values[$name][$declarationName] = is_array($declaration) ? ($declaration['Default'] ?? null) : null;
			}
		}

		try
		{
			return EffectiveConfiguration::create(
				identity: $identity,
				userId: $userId,
				installedRevision: $installedRevision,
				declarations: $declarations,
				sections: $values,
			)->getFingerprint();
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	/**
	 * Origin settings of the copy together with the number of values each of them has, because exactly one value
	 * of each is an invariant of a managed copy.
	 *
	 * @return array{code: ?string, codeCount: int, version: ?string, versionCount: int}
	 */
	private static function readOriginSettings(int $templateId): array
	{
		$origin = ['code' => null, 'codeCount' => 0, 'version' => null, 'versionCount' => 0];
		if ($templateId <= 0)
		{
			return $origin;
		}

		$rows = WorkflowTemplateSettingsTable::query()
			->setSelect(['NAME', 'VALUE'])
			->where('TEMPLATE_ID', $templateId)
			->whereIn('NAME', [
				WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE,
				WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION,
			])
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$value = is_string($row['VALUE']) && $row['VALUE'] !== '' ? $row['VALUE'] : null;

			if ($row['NAME'] === WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE)
			{
				$origin['code'] ??= $value;
				$origin['codeCount']++;
			}
			else
			{
				$origin['version'] ??= $value;
				$origin['versionCount']++;
			}
		}

		return $origin;
	}

	/**
	 * Takes the writes of a failed block back and lets nothing of the rollback itself leave.
	 *
	 * A rollback of a nested transaction returns the rows to its savepoint and throws afterwards. That throw is
	 * swallowed on purpose: what leaves the caller has to be the failure being compensated, and answering the
	 * throw with a second rollback would close the transaction of the caller instead of this one.
	 */
	private static function rollbackQuietly(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * Holds the row of the copy and the rows of its settings until the current transaction ends, so the final
	 * verification of an enabling cannot race a change of either of them.
	 */
	private static function lockCopyRows(int $templateId): void
	{
		$connection = Application::getConnection();

		$connection->query((new SqlExpression(
			'SELECT ?# FROM ?# WHERE ?# = ?i FOR UPDATE',
			'ID',
			WorkflowTemplateTable::getTableName(),
			'ID',
			$templateId,
		))->compile())->fetchAll();

		$connection->query((new SqlExpression(
			'SELECT ?# FROM ?# WHERE ?# = ?i FOR UPDATE',
			'ID',
			WorkflowTemplateSettingsTable::getTableName(),
			'TEMPLATE_ID',
			$templateId,
		))->compile())->fetchAll();
	}

	/**
	 * Codes of the given list that already hold a bot of this class.
	 *
	 * @param list<string> $codes
	 * @return array<string, true>
	 */
	private static function readExistingBotCodes(string $botClass, array $codes): array
	{
		if ($codes === [])
		{
			return [];
		}

		$rows = BotTable::query()
			->setSelect(['CODE'])
			->where('CLASS', ltrim($botClass, '\\'))
			->whereIn('CODE', $codes)
			->fetchAll()
		;

		$existing = [];
		foreach ($rows as $row)
		{
			$existing[(string)$row['CODE']] = true;
		}

		return $existing;
	}

	/**
	 * Values of the constants the setup blocks of the started copy really declare.
	 *
	 * The setup activity rejects a value whose constant is not one of its own, therefore the intersection is
	 * taken from the blocks of this very instance and not from the whole declaration of the template.
	 *
	 * @param array<string, mixed> $constants effective values of the constants section
	 * @return array<string, mixed>
	 */
	private static function selectSetupConstants(array $constants, ?BlockCollection $blocks): array
	{
		if ($blocks === null)
		{
			return [];
		}

		$values = [];
		foreach ($blocks as $block)
		{
			foreach ($block->items as $item)
			{
				if ($item instanceof Constant && array_key_exists($item->id, $constants))
				{
					$values[$item->id] = $constants[$item->id];
				}
			}
		}

		return $values;
	}

	/**
	 * Result of a participant, with an escaping exception converted into a failed result of a stable internal
	 * code: an exception of a participant never crosses the boundary of the public command.
	 *
	 * @param callable(): ManagedResourceCleanupResult $call
	 */
	private static function runParticipant(callable $call): ManagedResourceCleanupResult
	{
		try
		{
			return $call();
		}
		catch (\Throwable)
		{
			return ManagedResourceCleanupResult::createFailed(self::CODE_STORAGE_FAILED);
		}
	}

	private static function describeNewInstance(
		ManagedAgentIdentity $identity,
		int $userId,
		string $fingerprint,
	): ManagedAgentInstance
	{
		return new ManagedAgentInstance(
			id: null,
			identityHash: $identity->getHash(),
			systemCode: $identity->getSystemCode(),
			contextNamespace: $identity->getNamespace(),
			contextType: $identity->getType(),
			contextId: $identity->getContextId(),
			userId: $userId,
			templateId: null,
			state: ManagedAgentInstanceState::Enabling,
			configFingerprint: $fingerprint,
			nextRetryAt: self::describeWatchdogDeadline(),
		);
	}

	/**
	 * Identity of the stored components of the instance, or null when they cannot form a canonical one.
	 */
	private static function buildIdentity(ManagedAgentInstance $instance): ?ManagedAgentIdentity
	{
		try
		{
			$identity = ManagedAgentIdentity::create(
				$instance->getSystemCode(),
				new SystemAiAgentContext(
					namespace: $instance->getContextNamespace(),
					type: $instance->getContextType(),
					id: $instance->getContextId(),
				),
			);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return $identity->matchesStoredComponents($instance) ? $identity : null;
	}

	private static function describeWatchdogDeadline(): DateTime
	{
		return DateTime::createFromTimestamp(time() + self::ENABLING_WATCHDOG_SECONDS);
	}

	/**
	 * Deadline of the retry a failure of the given ordinal number is charged with; the background continuation
	 * reuses this policy instead of keeping a second copy of the delays.
	 */
	public static function describeRetryDeadline(int $retryCount): DateTime
	{
		$index = min(max($retryCount, 1), count(self::RETRY_DELAY_MINUTES)) - 1;

		return DateTime::createFromTimestamp(time() + self::RETRY_DELAY_MINUTES[$index] * 60);
	}

	private static function isWatchdogExpired(ManagedAgentInstance $instance): bool
	{
		$deadline = $instance->getNextRetryAt();

		return $deadline === null || $deadline->getTimestamp() <= time();
	}

	/**
	 * Whether the launching user exists and may be used by the standard start service; the application rights on
	 * the external object stay the responsibility of the calling module.
	 */
	private static function isLaunchingUserUsable(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$row = UserTable::query()
			->setSelect(['ID'])
			->where('ID', $userId)
			->where('ACTIVE', 'Y')
			->setLimit(1)
			->fetch()
		;

		return is_array($row);
	}

	private function rejectInput(string $operation, ArgumentException $exception): SystemAiAgentLifecycleResult
	{
		$code = (string)$exception->getParameter() === 'systemCode'
			? SystemAiAgentErrorCode::AgentNotAvailable
			: SystemAiAgentErrorCode::InvalidContext
		;

		// A field that did not pass its check is skipped in the journal instead of being written as it came.
		return $this->fail($operation, null, $code);
	}

	private static function mapCapabilityFailure(Result $result): SystemAiAgentErrorCode
	{
		return self::firstErrorCode($result) === SystemAiAgentCapabilityValidator::ERROR_UNSUPPORTED_RESOURCE
			? SystemAiAgentErrorCode::UnsupportedResource
			: SystemAiAgentErrorCode::AgentNotAvailable
		;
	}

	/**
	 * Public code of a rejected activation: the section of the error tells a parameter from a constant.
	 */
	private static function mapConfigurationFailure(Result $result): SystemAiAgentErrorCode
	{
		$error = $result->getErrors()[0] ?? null;
		$customData = $error instanceof Error ? $error->getCustomData() : null;
		$section = is_array($customData)
			? (string)($customData[SystemAiAgentConfigurationResolver::CUSTOM_DATA_SECTION] ?? '')
			: ''
		;
		$isConstant = $section === SystemAiAgentActivationParameters::SECTION_CONSTANTS;

		return match (self::firstErrorCode($result))
		{
			SystemAiAgentConfigurationResolver::ERROR_INVALID_USER => SystemAiAgentErrorCode::InvalidUser,
			SystemAiAgentConfigurationResolver::ERROR_UNKNOWN_NAME => $isConstant
				? SystemAiAgentErrorCode::UnknownConstant
				: SystemAiAgentErrorCode::UnknownParameter,
			SystemAiAgentConfigurationResolver::ERROR_REQUIRED_MISSING => $isConstant
				? SystemAiAgentErrorCode::MissingRequiredConstant
				: SystemAiAgentErrorCode::MissingRequiredParameter,
			SystemAiAgentConfigurationResolver::ERROR_INVALID_VALUE,
			SystemAiAgentConfigurationResolver::ERROR_LIMIT_EXCEEDED => $isConstant
				? SystemAiAgentErrorCode::InvalidConstant
				: SystemAiAgentErrorCode::InvalidParameter,
			default => SystemAiAgentErrorCode::AgentNotAvailable,
		};
	}

	private static function firstErrorCode(Result $result): string
	{
		$error = $result->getErrors()[0] ?? null;

		return $error instanceof Error ? (string)$error->getCode() : '';
	}

	/**
	 * Terminal result of a pass that is a record of the journal only when the pass is the operation itself: a
	 * compensation of an enabling reports its own outcome instead.
	 */
	private function terminal(
		string $operation,
		ManagedAgentIdentity $identity,
		bool $journaled,
		SystemAiAgentErrorCode $code,
		?string $internalCode,
		?SystemAiAgentLifecycleOutcome $outcome,
		?int $instanceId,
	): SystemAiAgentLifecycleResult
	{
		return $journaled
			? $this->fail($operation, $identity, $code, $internalCode, outcome: $outcome, instanceId: $instanceId)
			: SystemAiAgentLifecycleResult::createFailure($code, $outcome)
		;
	}

	private function succeed(
		string $operation,
		?ManagedAgentIdentity $identity,
		SystemAiAgentLifecycleOutcome $outcome,
		?int $instanceId = null,
		?int $userId = null,
	): SystemAiAgentLifecycleResult
	{
		$this->journal($operation, $identity, $outcome, null, null, $instanceId, $userId);

		return SystemAiAgentLifecycleResult::createSuccess($outcome);
	}

	private function fail(
		string $operation,
		?ManagedAgentIdentity $identity,
		SystemAiAgentErrorCode $code,
		?string $internalCode = null,
		?SystemAiAgentLifecycleOutcome $outcome = null,
		?int $instanceId = null,
		?int $userId = null,
		?string $cause = null,
	): SystemAiAgentLifecycleResult
	{
		$this->journal($operation, $identity, $outcome, $code, $internalCode, $instanceId, $userId, $cause);

		return SystemAiAgentLifecycleResult::createFailure($code, $outcome);
	}

	/**
	 * Writes the structured record of an outcome.
	 *
	 * The identity is represented by an HMAC token of its hash under a purpose of its own, so the records of one
	 * instance can be correlated without exposing the hash itself. The identity hash, the original context id,
	 * the parameters, the constants, the whole DTO and the text of an exception have no field here at all, and a
	 * field that could not be built is skipped instead of being written as it came.
	 *
	 * @param string|null $cause class of the exception a failed step was refused with, or the internal code of the
	 *  invariant it broke; either is a name of the code and carries no value, while the text of an exception stays
	 *  out of the record like every other value
	 */
	private function journal(
		string $operation,
		?ManagedAgentIdentity $identity,
		?SystemAiAgentLifecycleOutcome $outcome,
		?SystemAiAgentErrorCode $code,
		?string $internalCode,
		?int $instanceId,
		?int $userId,
		?string $cause = null,
	): void
	{
		$logger = $this->getLogger();
		if ($logger === null)
		{
			return;
		}

		$context = ['operation' => $operation];

		if ($identity !== null)
		{
			$token = self::buildIdentityToken($identity);
			if ($token !== null)
			{
				$context['identityToken'] = $token;
			}

			$context['systemCode'] = $identity->getSystemCode();
			$context['namespace'] = $identity->getNamespace();
			$context['type'] = $identity->getType();
		}

		if ($instanceId !== null && $instanceId > 0)
		{
			$context['instanceId'] = $instanceId;
		}

		if ($userId !== null && $userId > 0)
		{
			$context['userId'] = $userId;
		}

		if ($outcome !== null)
		{
			$context['outcome'] = $outcome->value;
		}

		if ($code !== null)
		{
			$context['errorCode'] = $code->value;
		}

		if ($internalCode !== null && $internalCode !== '')
		{
			$context['reason'] = $internalCode;
		}

		if ($cause !== null && $cause !== '')
		{
			$context['cause'] = $cause;
		}

		if ($code === null)
		{
			$logger->info(self::LOG_MESSAGE, $context);

			return;
		}

		$logger->warning(self::LOG_MESSAGE, $context);
	}

	private static function buildIdentityToken(ManagedAgentIdentity $identity): ?string
	{
		try
		{
			return (new Signer())->getSignature($identity->getHash(), self::LOG_IDENTITY_PURPOSE);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Null while the logger is switched off in the registry: there is nothing to write the diagnostics to, so the
	 * journal is skipped instead of feeding a logger that discards everything.
	 *
	 * The answer is resolved once and kept for the life of the service, the negative one as well: an operation
	 * writes several records, and a switched off journal would otherwise build the factory for each of them.
	 */
	private function getLogger(): ?LoggerInterface
	{
		if (!$this->loggerResolved)
		{
			$this->loggerResolved = true;
			$this->logger ??= (new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID);
		}

		return $this->logger;
	}
}
