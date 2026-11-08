<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller\Integration\AiAgent;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\AiAgentStartResult;
use CBPWorkflowTemplateUser;

use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\DI\ServiceLocator;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Enum\Template\CreateSource;

use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ItemType;
use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsGridHelper;
use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectFieldStateResolver;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Public\Provider\WorkflowTemplate\AiAgentProvider;
use Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityServiceInterface;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\ExistingRunsWarningPolicy;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\LaunchableTemplateResolver;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\OwnershipTransferService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\SystemTemplateActivationService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\TemplateDeleteService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade\AgentUpgradeService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade\UpgradeStatus;
use Bitrix\Bizproc\Internal\Service\Feature\AiAgentsFeature;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;


class Template extends BaseController
{
	// Mirrors AgentUpgradeService's audit type so the interim post-upgrade re-launch outcome is
	// discoverable alongside the upgrade attempt in the event log.
	private const UPGRADE_RELAUNCH_AUDIT_TYPE = 'bizproc_ai_agent_upgrade';

	// API-01 (NORMATIVE) restart-action error codes; the frontend keys the setup panel / notices
	// off these. templateStale is the ERR-002 data-actuality code; accessDenied is the ownership
	// denial. The tariff/region denial keeps AiAgentsFeature's existing code (shared tariff-slider
	// UX), so only these two are asserted here.
	private const ERROR_CODE_ACCESS_DENIED = 'accessDenied';
	private const ERROR_CODE_TEMPLATE_STALE = 'templateStale';

	private readonly SystemTemplateActivationService $activationService;
	private readonly TemplateDeleteService $templateDeleteService;
	private readonly AiAgentsGridHelper $aiAgentGridHelper;
	private readonly AiAgentsFeature $aiAgentsFeature;
	private readonly AiAgentProvider $aiAgentProvider;
	private readonly AiAgentRepository $aiAgentRepository;
	private readonly AgentUpgradeService $agentUpgradeService;
	private readonly RegionAvailabilityServiceInterface $regionAvailabilityService;
	private readonly SetupTemplateService $setupTemplateService;
	private readonly LaunchableTemplateResolver $launchableTemplateResolver;
	private readonly ExistingRunsWarningPolicy $existingRunsWarningPolicy;
	private readonly OwnershipTransferService $ownershipTransferService;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$this->activationService = ServiceLocator::getInstance()->get(SystemTemplateActivationService::class);
		$this->templateDeleteService = ServiceLocator::getInstance()->get(TemplateDeleteService::class);
		$this->aiAgentGridHelper = ServiceLocator::getInstance()->get(AiAgentsGridHelper::class);
		$this->aiAgentsFeature = ServiceLocator::getInstance()->get(AiAgentsFeature::class);
		$this->aiAgentProvider = ServiceLocator::getInstance()->get(AiAgentProvider::class);
		$this->aiAgentRepository = ServiceLocator::getInstance()->get(AiAgentRepository::class);
		$this->agentUpgradeService = ServiceLocator::getInstance()->get(AgentUpgradeService::class);
		$this->regionAvailabilityService =
			ServiceLocator::getInstance()->get(RegionAvailabilityServiceInterface::class)
		;
		$this->setupTemplateService = ServiceLocator::getInstance()->get(SetupTemplateService::class);
		$this->launchableTemplateResolver = ServiceLocator::getInstance()->get(LaunchableTemplateResolver::class);
		$this->existingRunsWarningPolicy = ServiceLocator::getInstance()->get(ExistingRunsWarningPolicy::class);
		$this->ownershipTransferService = ServiceLocator::getInstance()->get(OwnershipTransferService::class);
	}

	/**
	 * Restricts the state-changing endpoints to POST (security hardening). Appended via
	 * '+prefilters' so Engine's default Authentication and CSRF guards are kept; the default
	 * HttpMethod filter allows GET+POST, this stricter one rejects GET. Other actions keep the
	 * framework defaults untouched. The frontend already calls upgrade over POST (both the grid
	 * action request and the constants submit); checkExistingRuns is here because it spends the
	 * personal right to see the warning, so it is not a safe method.
	 */
	public function configureActions()
	{
		return [
			'upgrade' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'checkExistingRuns' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'changeOwner' => [
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
		];
	}

	/**
	 * Pre-flight check before copying and starting a system AI-agent template (API-01).
	 *
	 * Answers whether the user should be warned that agents from this template are already
	 * running, and - in that case only - spends the personal right to see that warning. The right
	 * is spent atomically inside this call, so a repeated call by the same user answers
	 * showWarning: false. The action must not be replayed "just in case".
	 *
	 * The template first has to pass the launchable predicate (PRED-01): without it the method
	 * would answer "are there running agents" for an arbitrary id and would reveal the system code
	 * of a hidden template. Every rejection reason is reported as the same accessDenied error.
	 *
	 * The system code comes from the predicate's own result - the weaker getSystemAiAgentCode() is
	 * deliberately not used here. A user-created template from the designer has no system code:
	 * the warning gate is skipped and the answer is showWarning: false, systemCode: null.
	 *
	 * Tariff unavailability is not inherited by this controller family; it is expressed as
	 * showWarning: false by the policy. The regional gate is inherited from BaseController.
	 *
	 * @return array{showWarning: bool, systemCode: ?string} DTO-01; systemCode is non-null only
	 *   together with showWarning: true.
	 */
	public function checkExistingRunsAction(int $templateId): array
	{
		$launchable = $this->launchableTemplateResolver->resolve($templateId);
		if (!$launchable->isSuccess())
		{
			$this->addErrors($launchable->getErrors());

			return [];
		}

		$systemCode = $launchable->getData()[LaunchableTemplateResolver::SYSTEM_CODE] ?? null;
		$showWarning = $systemCode !== null && $this->existingRunsWarningPolicy->shouldShowWarning($systemCode);

		return [
			'showWarning' => $showWarning,
			'systemCode' => $showWarning ? $systemCode : null,
		];
	}

	/**
	 * Restart action (API-01). Branch selection and the server-side EVENT-01 payload live in
	 * SystemTemplateActivationService; here only the guards and the API-01 response shape are
	 * assembled. The request carries the template id only — restartRunId/previousRunId/initiatorId/
	 * timestamp and the resolved agent instance are all server-side, never from the body (IDOR guard).
	 *
	 * Check order (server-side only, ALG-01): tariff/region -> owner/admin -> data actuality.
	 *   - tariff/region unavailable -> restartUnavailable (AiAgentsFeature error);
	 *   - not owner/admin of an existing launched copy -> accessDenied;
	 *   - the launched copy no longer exists / is no longer restartable -> templateStale (ERR-002),
	 *     no start, no silent fallback.
	 *
	 * On success the branch was already chosen once (restart vs start); a failed restart start is
	 * reported and never replayed as a start (AC-022). The response carries setupTemplateData when
	 * the run suspended for constant input, plus runType and (for the restart branch) restartRunId.
	 */
	public function startAction(int $templateId): array
	{
		if (!$this->isRestartAvailable($templateId))
		{
			return [];
		}

		if (!$this->canCurrentUserManageLaunchedTemplate($templateId, requireStarted: true))
		{
			$this->addLaunchedTemplateDenial($templateId);

			return [];
		}

		$includeResult = $this->activationService->includeModuleAi();

		if (!$includeResult->isSuccess())
		{
			$this->addErrors($includeResult->getErrors());
			return [];
		}

		$startResult = $this->activationService->startTemplate($templateId, asRestart: true);

		$this->addErrors($startResult->getErrors());

		return $startResult->getData();
	}

	/**
	 * Hands a launched AI-agent copy over to another employee (API-01 changeOwner).
	 *
	 * The acting user is resolved server-side; the request carries only the copy, the owner the caller
	 * saw and the recipient. The expected owner is a write condition, not a right: a copy whose owner
	 * changed after the grid was rendered is refused as templateStale instead of being handed over on
	 * top of somebody else's ownership.
	 *
	 * Check order mirrors startAction: tariff/region -> transfer right -> operation. The right is asked
	 * here only to answer with a precise error code; OwnershipTransferService asks it again and stays
	 * the source of truth for every other caller of the operation.
	 *   - tariff/region unavailable -> the codes AiAgentsFeature and BaseController already established;
	 *   - no right against a still launched copy the caller still sees the owner of -> accessDenied;
	 *   - the launched copy is gone, no longer started, or owned by somebody else by now -> templateStale
	 *     (ERR-002).
	 *
	 * @return array{ownerId: int} Owner the copy ended up with; empty on any refusal.
	 */
	public function changeOwnerAction(int $templateId, int $expectedOwnerId, int $newOwnerId): array
	{
		if (!$this->isRestartAvailable($templateId))
		{
			return [];
		}

		$currentUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
		$canTransfer = $this->aiAgentProvider->canTransferOwnership(
			$templateId,
			(int)$currentUser->getId(),
			$currentUser->isAdmin(),
		);

		if (!$canTransfer)
		{
			$this->addTransferDenial($templateId, $expectedOwnerId);

			return [];
		}

		$transferResult = $this->ownershipTransferService->transfer(
			$templateId,
			$expectedOwnerId,
			$newOwnerId,
			$currentUser,
		);

		if (!$transferResult->isSuccess())
		{
			$this->addErrors($this->mapTransferErrors($transferResult->getErrors()));

			return [];
		}

		return ['ownerId' => $newOwnerId];
	}

	/**
	 * Copies a launchable AI-agent template and starts the copy.
	 *
	 * Admission is the shared launchable predicate (PRED-01), the same one the pre-flight check
	 * uses, so the two paths cannot diverge: a system template of the catalogue is copied as a
	 * product feature with no role ACL involved, while a user-created one (added via the designer)
	 * additionally asks the template ACL for the edit right on the source. A launched copy, a
	 * template of another type/section, a missing template, a system template hidden by the registry
	 * and a user-created one the user may not edit are all rejected as the same accessDenied.
	 */
	public function copyAndStartAction(int $templateId): array
	{
		$launchable = $this->launchableTemplateResolver->resolve($templateId);
		if (!$launchable->isSuccess())
		{
			$this->addErrors($launchable->getErrors());

			return [];
		}

		// SYSTEM_CODE is null for a user-created agent; every code that reaches this point is copied
		// as CreateSource::User, because the only scenario-sourced one is hidden by the resolver
		$createSource = $this->resolveCopyCreateSource(
			$launchable->getData()[LaunchableTemplateResolver::SYSTEM_CODE] ?? null,
		);
		if (
			$createSource === CreateSource::User
			 && !$this->isAgentsFeatureAvailable()
		)
		{
			return [];
		}

		$includeResult = $this->activationService->includeModuleAi();

		if (!$includeResult->isSuccess())
		{
			$this->addErrors($includeResult->getErrors());

			return [];
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return [];
		}

		$copyResult = $this->activationService->copyTemplate($templateId, $userId, $createSource);
		if (!$copyResult instanceof TemplateCreatedResult)
		{
			$this->addErrors($copyResult->getErrors());

			return [];
		}

		$startResult = $this->activationService->startTemplate($copyResult->templateId);
		if (!$startResult->isSuccess())
		{
			$this->addErrors($startResult->getErrors());
			$deleteResult = $this->templateDeleteService->rollbackFailedTemplateCopy(
				templateId: $copyResult->templateId,
				initiator: new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser),
			);
			$this->addErrors($deleteResult->getErrors());

			return [];
		}

		return $this->prepareCopyAndStartResponseData($copyResult, $startResult);
	}

	/**
	 * Upgrade a launched AI-agent copy to the current reference template version (API-01).
	 *
	 * The upgrade master is always shown for review before applying (ADR §10.3). The first
	 * call omits $constantValues and returns the review payload; the copy is written only on a
	 * subsequent submit that carries $constantValues (value-passthrough), and only after the
	 * server re-validates the required constants (the UI is not the source of truth).
	 *
	 * Check order (server-side only): tariff + region -> admin-only rights ->
	 * launched-copy ownership/link -> AgentUpgradeService.
	 *
	 * Response (API-01, NORMATIVE):
	 *   review (first call / re-validation) ->
	 *     {
	 *       status: "needs_review",
	 *       blocks: [...],                       // setup blocks of every editable constant of the new version
	 *       values: { <constantCode>: <value> }, // values to pre-fill the master: current copy values on
	 *                                            //   the first call, the submitted values (merged over
	 *                                            //   current) on a re-validation, so user input is kept
	 *       requiredConstants: [<code>, ...],    // required constants still missing a value (may be empty)
	 *       invalidConstants: [<code>, ...],     // submitted values that failed field-type re-validation
	 *                                            //   (empty on the first call; empty when all are valid)
	 *     }
	 *   applied (submit)  -> { status: "updated", row: <grid row with DTO-01 fields> }
	 *   conflict/error    -> errors payload (ERR-002/003/005/006), no partial changes.
	 *
	 * blocks/values are derived from the reference template and the merge, never from the
	 * not-yet-written copy.
	 *
	 * @param array<string, mixed>|null $constantValues Submitted constant values keyed by code.
	 *   Null/omitted on the first call (show the master); a non-null map applies the upgrade.
	 */
	public function upgradeAction(int $templateId, ?array $constantValues = null): array
	{
		if (!$this->isAgentsFeatureAvailable())
		{
			return [];
		}

		if (!$this->regionAvailabilityService->isAvailable())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return [];
		}

		$currentUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);

		// Admin-only: tightened over the generic launched-template guard (403 on the frontend).
		if (!$currentUser->isAdmin())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return [];
		}

		if (!$this->canCurrentUserManageLaunchedTemplate($templateId))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return [];
		}

		$trustedProjectConstantValues = $this->getTrustedProjectConstantValuesForUpgradeReview(
			$constantValues,
			$this->getStoredTemplateConstantValues($templateId),
		);

		$result = $this->agentUpgradeService->upgrade($templateId, $constantValues);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return ['status' => $result->getStatus()->value];
		}

		if ($result->getStatus() === UpgradeStatus::NeedsReview)
		{
			$values = $result->getValues();
			$blocks = $this->setupTemplateService->getRequiredConstantBlocks(
				$result->getReferenceTemplateId(),
				array_keys($values),
			);

			return [
				'status' => UpgradeStatus::NeedsReview->value,
				// Setup blocks of every editable constant of the new version, filtered from the
				// reference setup-template blocks by the merged constant codes.
				'blocks' => $this->setupTemplateService->enrichProjectConstantBlocks(
					$blocks,
					$trustedProjectConstantValues,
					(int)$currentUser->getId(),
				),
				// Values to pre-fill the master, keyed by constant code: current copy values on the
				// first call, the submitted values (merged over current) on a re-validation.
				'values' => $values,
				// Required constants still missing a value (empty when none) — marked mandatory.
				'requiredConstants' => $result->getRequiredConstants(),
				// Submitted values that failed field-type re-validation (empty on the first call).
				'invalidConstants' => $result->getInvalidConstants(),
			];
		}

		// The upgrade is applied and committed (AgentUpgradeService owns the transaction), so this
		// runs strictly after commit. Bring the copy up on the new version through the manual-start
		// two-part handshake (startTemplate + fill), the same path as the grid "Restart" action
		// (ADR §10.3 step 5 / §10.5). The submitted, just-reviewed setup values are the
		// authoritative fill payload. Best-effort: a re-launch failure never undoes the apply.
		$this->relaunchAfterUpgrade($templateId, $constantValues ?? []);

		return [
			'status' => UpgradeStatus::Updated->value,
			'row' => $this->aiAgentGridHelper->getRowFieldsByTemplateId($templateId),
		];
	}

	/**
	 * Re-launches a freshly upgraded copy on the new version via the manual-start two-part
	 * handshake — the same path as the grid "Restart" action (startAction plus the setup-panel
	 * submit), applied here server-side (ADR §10.3 step 5 / §10.5).
	 *
	 * Called strictly after AgentUpgradeService has applied and committed the in-place upgrade
	 * (that service owns the transaction), so this runs outside any transaction — startTemplate()
	 * starts a workflow, and MySQL does not support the nested transaction that wrapping it would
	 * create.
	 *
	 * The handshake is two parts, both mandatory:
	 *   1. startTemplate() fires the AiAgentStartTrigger; the workflow starts and suspends
	 *      (CBPWorkflowStatus::Suspended) waiting for its setup constants.
	 *   2. fill() delivers those constants to the suspended instance, which then resumes and
	 *      completes — the agent is really up on the new version. Skipping fill() would leave a
	 *      Suspended orphan and the agent would never run. fill() runs only when the start
	 *      actually suspended for setup (startResult->setupTemplateEvent captured); otherwise the
	 *      agent already completed and this step is skipped — there is nothing to fill.
	 *
	 * The re-launch is attributed to the copy owner (ACTIVATED_BY), not the administrator who
	 * triggered the upgrade: the onboarding start branch reads the starting user
	 * ({=AiAgentStartTrigger:startedBy}) for the greeting, bot author and responsible, so an
	 * admin upgrading someone else's copy must not redirect those to the admin. The owner is used
	 * for both handshake steps; the current user is only a fallback when the owner cannot be
	 * resolved (preserving the prior behaviour).
	 *
	 * The same userId is used for both steps: startTemplate() registers a setup-data handler keyed
	 * by userId, and fill() must match it. A NEW instance is started on the new version (AC-018);
	 * any previously running instance is left untouched (AC-017).
	 *
	 * Both steps are best-effort: the upgrade is already committed, so a failure here must never
	 * undo it — it is only journaled for observability. A start that reports success but yields no
	 * instance id (trigger not applied) is treated as a failure, so the interim silent-drop is no
	 * longer masked as a successful re-launch.
	 *
	 * @param array<string, mixed> $constantValues Authoritative, just-reviewed setup constant
	 *   values (from the upgrade submit); narrowed to setup-block codes before fill.
	 */
	private function relaunchAfterUpgrade(int $templateId, array $constantValues = []): void
	{
		try
		{
			$userId = $this->aiAgentRepository->getActivatedBy($templateId) ?? (int)CurrentUser::get()->getId();

			$startResult = $this->activationService->startTemplate($templateId, $userId);
			$instanceId = $startResult->getData()['templateWorkflowIds'][$templateId] ?? null;

			// A missing instance id means the workflow never started (e.g. the trigger was not
			// applied); startTemplate() can still report success in that case, so guard explicitly
			// instead of trusting isSuccess() alone.
			if (!$startResult->isSuccess() || empty($instanceId))
			{
				$errorMessages = $startResult->getErrorMessages();
				$this->journalRelaunchFailure(
					$templateId,
					$errorMessages === [] ? ['no workflow started / trigger not applied'] : $errorMessages,
				);

				return;
			}

			// The started workflow only suspends for setup when startTemplate() captured a
			// setup-data event with blocks (SystemTemplateActivationService::startTemplate):
			// that is the exact point fill() resumes. Without it the agent already ran to
			// completion (no SetupTemplateActivity, or it finished synchronously) and there is
			// no suspended instance to deliver constants to — nothing to fill.
			if ($startResult->setupTemplateEvent === null)
			{
				return;
			}

			// Second handshake step: feed the setup constants so the suspended instance resumes
			// and completes. fill() rejects any non-setup code, so narrow to setup-block codes first.
			$fillResult = $this->setupTemplateService->fill(
				$userId,
				$templateId,
				(string)$instanceId,
				$this->extractSetupBlockValues($templateId, $constantValues),
				skipAccessValidation: true,
				applyDefaults: true,
			);

			// The workflow suspended for setup, so any fill() failure here is genuine (not a
			// benign "nothing to deliver") and must be journaled for observability.
			if (!$fillResult->isSuccess())
			{
				$this->journalRelaunchFailure($templateId, $fillResult->getErrorMessages());
			}
		}
		catch (\Throwable $e)
		{
			$this->journalRelaunchFailure($templateId, [$e->getMessage()]);
		}
	}

	/**
	 * Narrows constant values to the template's setup-block constant codes, so fill() is never
	 * handed a non-setup code (e.g. a derived BotName) that it would reject.
	 *
	 * Setup-block codes are the ids of the 'constant' items in the template's setup blocks; after
	 * apply the copy carries the reference SetupTemplateActivity, so its own id resolves the blocks.
	 *
	 * @param array<string, mixed> $constantValues
	 * @return array<string, mixed>
	 */
	private function extractSetupBlockValues(int $templateId, array $constantValues): array
	{
		if ($constantValues === [])
		{
			return [];
		}

		$blocks = $this->setupTemplateService->getRequiredConstantBlocks(
			$templateId,
			array_keys($constantValues),
		);

		$setupValues = [];
		foreach ($blocks as $block)
		{
			foreach ((array)($block['items'] ?? []) as $item)
			{
				$code = $item['id'] ?? null;
				if (
					($item['itemType'] ?? null) === ItemType::Constant->value
					&& $code !== null
					&& array_key_exists($code, $constantValues)
				)
				{
					$setupValues[$code] = $constantValues[$code];
				}
			}
		}

		return $setupValues;
	}

	/**
	 * Project state can be resolved only for the saved copy values.
	 * Submitted values are used solely to check whether that saved value is still selected.
	 *
	 * @param array<string, mixed>|null $submittedConstantValues
	 * @param array<string, mixed> $storedConstantValues
	 * @return array<string, mixed>
	 */
	private function getTrustedProjectConstantValuesForUpgradeReview(
		?array $submittedConstantValues,
		array $storedConstantValues,
	): array
	{
		if ($submittedConstantValues === null)
		{
			return $storedConstantValues;
		}

		$trustedValues = [];
		foreach ($storedConstantValues as $code => $storedValue)
		{
			if (
				array_key_exists($code, $submittedConstantValues)
				&& $this->isSameProjectConstantValue($submittedConstantValues[$code], $storedValue)
			)
			{
				$trustedValues[$code] = $storedValue;
			}
		}

		return $trustedValues;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getStoredTemplateConstantValues(int $templateId): array
	{
		$values = [];
		foreach ((array)\CBPWorkflowTemplateLoader::getTemplateConstants($templateId) as $code => $constant)
		{
			if (is_array($constant))
			{
				$values[(string)$code] = $constant['Default'] ?? $constant['default'] ?? null;
			}
		}

		return $values;
	}

	private function isSameProjectConstantValue(mixed $submittedValue, mixed $reviewValue): bool
	{
		return $this->normalizeProjectConstantValue($submittedValue) === $this->normalizeProjectConstantValue($reviewValue);
	}

	private function normalizeProjectConstantValue(mixed $value): array
	{
		$normalized = (new ProjectFieldStateResolver())->extractProjectIds($value);
		sort($normalized);

		return $normalized;
	}

	/**
	 * Records a best-effort warning when the post-upgrade re-launch does not start (observability
	 * only). Swallows its own errors so a logging failure can never affect the already-applied
	 * upgrade.
	 *
	 * @param list<string> $errorMessages
	 */
	private function journalRelaunchFailure(int $templateId, array $errorMessages): void
	{
		try
		{
			\CEventLog::Log(
				'WARNING',
				self::UPGRADE_RELAUNCH_AUDIT_TYPE,
				'bizproc',
				(string)$templateId,
				sprintf(
					'AI-agent upgrade re-launch failed: templateId=%d errors=%s',
					$templateId,
					implode('; ', $errorMessages),
				),
			);
		}
		catch (\Throwable)
		{
			// Observability is best-effort; a logging failure must not affect the applied upgrade.
		}
	}

	/**
	 * @param array<int> $agentIds
	 */
	public function deleteAction(array $agentIds, bool $deleteChatbots = false): array
	{
		$currentUser = new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$deleteResult = $this->templateDeleteService->deleteTemplates(
			templateIds: $agentIds,
			initiator: $currentUser,
			deleteChatbots: $deleteChatbots,
		);

		$this->addErrors($deleteResult->getErrors());

		return [];
	}
	
	public function fetchRowAction(int $templateId): array
	{
		if (!$this->isRestartAvailable($templateId))
		{
			return [];
		}

		return $this->aiAgentGridHelper->getRowFieldsByTemplateId($templateId);
	}

	private function prepareCopyAndStartResponseData(Result $copyResult, AiAgentStartResult $startResult): array
	{
		$data = $copyResult->getData();
		$rawFields = (array)($data['rawTemplateFields'] ?? []);

		if (empty($rawFields))
		{
			return [];
		}

		$gridFields = $this->aiAgentGridHelper->prepareGridRowDataFromTemplateFields($rawFields);

		return $gridFields + [
			AiAgentStartResult::SETUP_TEMPLATE_DATA => $startResult->setupTemplateEvent?->toArray()
		];
	}

	private function isAgentsFeatureAvailable(): bool
	{
		$isAiAgentFeatureAvailable = $this->aiAgentsFeature->isAvailable();

		if ($isAiAgentFeatureAvailable)
		{
			return true;
		}

		$error = $this->aiAgentsFeature->makeUnavailableByTariffError();
		$this->addError($error);

		return false;
	}

	private function canCurrentUserManageLaunchedTemplate(int $templateId, bool $requireStarted = false): bool
	{
		$currentUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);

		return $this->aiAgentProvider->canManageLaunchedTemplate(
			$templateId,
			(int)$currentUser->getId(),
			$currentUser->isAdmin(),
			$requireStarted,
		);
	}

	/**
	 * Reports a failed right check on a launched copy the way the section already answers it: a copy
	 * that is no longer a started launched one reads as templateStale (ERR-002), so the frontend can
	 * surface "data is out of date", and only a live copy the user may not manage reads as accessDenied.
	 */
	private function addLaunchedTemplateDenial(int $templateId): void
	{
		if (!$this->aiAgentProvider->isRestartableLaunchedTemplate($templateId))
		{
			$this->addError(
				ErrorMessage::TEMPLATE_NOT_FOUND->getError(
					['#ID#' => $templateId],
					self::ERROR_CODE_TEMPLATE_STALE,
				),
			);

			return;
		}

		$this->addError(ErrorMessage::ACCESS_DENIED->getError([], self::ERROR_CODE_ACCESS_DENIED));
	}

	/**
	 * Reports a failed transfer right the way changeOwner answers it. The launched-copy denial above tells
	 * a live copy from a gone one, and ownership adds a second way for the data to be out of date: the copy
	 * is still there, but its owner is no longer the one the grid rendered. The former owner has really
	 * lost the right by then, and answering accessDenied would leave a row the client knows to be current
	 * on screen; templateStale (ERR-002) is what makes it reload the row.
	 */
	private function addTransferDenial(int $templateId, int $expectedOwnerId): void
	{
		if (
			$this->aiAgentProvider->isRestartableLaunchedTemplate($templateId)
			&& $this->aiAgentProvider->getLaunchedCopyOwner($templateId) !== $expectedOwnerId
		)
		{
			$this->addError(
				ErrorMessage::TEMPLATE_NOT_FOUND->getError(
					['#ID#' => $templateId],
					self::ERROR_CODE_TEMPLATE_STALE,
				),
			);

			return;
		}

		$this->addLaunchedTemplateDenial($templateId);
	}

	/**
	 * "This employee already owns the agent" (Q-1) keeps its own wording but has no code of its own in
	 * API-01: what was wrong is the choice of recipient, so it reaches the client under the recipient
	 * code. Every other refusal already carries either an API-01 code of the operation or the code its
	 * own layer established, the same way the tariff and region denials do.
	 *
	 * @param array<Error> $errors
	 *
	 * @return list<Error>
	 */
	private function mapTransferErrors(array $errors): array
	{
		$mapped = [];
		foreach ($errors as $error)
		{
			$mapped[] = $error->getCode() === OwnershipTransferService::ERROR_CODE_ALREADY_OWNER
				? new Error(
					$error->getMessage(),
					OwnershipTransferService::ERROR_CODE_INVALID_RECIPIENT,
					$error->getCustomData(),
				)
				: $error
			;
		}

		return $mapped;
	}

	private function isRestartAvailable(int $templateId): bool
	{
		$isRestartAvailable = $this->aiAgentsFeature->isRestartAvailable($templateId);

		if ($isRestartAvailable)
		{
			return true;
		}

		$error = $this->aiAgentsFeature->makeUnavailableByTariffError();
		$this->addError($error);

		return false;
	}

	/**
	 * The CreateSource::Scenario branch is unreachable through copyAndStartAction: its only system
	 * code is hidden by HiddenAiAgentsRegistry, so LaunchableTemplateResolver denies access before a
	 * copy source is ever chosen. It is kept as the marker of the deferred tariff asymmetry for
	 * launches from trusted scenarios (see ADR), not as a live case.
	 *
	 * @todo Temporary workaround: for the bitrix_booking_ai_call system template we copy with CreateSource::Scenario.
	 *       Remove once a generic mechanism for resolving the copy source per template is in place.
	 */
	private function resolveCopyCreateSource(?string $systemCode): CreateSource
	{
		return $systemCode === 'bitrix_booking_ai_call'
			? CreateSource::Scenario
			: CreateSource::User
		;
	}
}
