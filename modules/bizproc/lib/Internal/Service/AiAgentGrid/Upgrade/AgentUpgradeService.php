<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;

/**
 * Applies an in-place upgrade of a single launched AI-agent copy to the current
 * reference template version (ALG-01).
 *
 * Flow: load the launched copy and its linked system template, compute the new
 * revision, transfer compatible constants, and — only when no new required
 * constant is left unset — atomically replace the copy logic, persist the merged
 * constants and record the new installed version. Any failure rolls back so the
 * previous version and constants stay intact (AC-021/AC-022, ERR-005).
 *
 * The service reads/writes only the template row and its settings; active
 * workflow instances (WorkflowInstanceTable) are never touched (AC-016/AC-017).
 */
class AgentUpgradeService
{
	// ERR codes from the SDD contract, propagated to the transport layer.
	private const ERR_NO_ORIGIN = 'ERR_NO_ORIGIN_SYSTEM_CODE'; // ERR-002
	private const ERR_REFERENCE_UNAVAILABLE = 'ERR_REFERENCE_UNAVAILABLE'; // ERR-003 / ERR-006
	private const ERR_ALREADY_UP_TO_DATE = 'ERR_ALREADY_UP_TO_DATE';
	private const ERR_APPLY_FAILED = 'ERR_APPLY_FAILED'; // ERR-005

	private const AUDIT_TYPE = 'bizproc_ai_agent_upgrade';

	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository = new AiAgentRepository(),
		private readonly TemplateRevisionService $templateRevisionService = new TemplateRevisionService(),
		private readonly ConstantMergeService $constantMergeService = new ConstantMergeService(),
		private readonly SetupTemplateService $setupTemplateService = new SetupTemplateService(),
	) {}

	/**
	 * The upgrade master is always shown for review before applying (ADR §10.3): the first
	 * call (no submitted values) returns NeedsReview carrying the current constant values to
	 * pre-fill and the reference template id whose setup blocks describe the editable
	 * constants. The upgrade is applied (Updated) only on a subsequent call that submits the
	 * values, and only after server-side re-validation confirms no required constant is left
	 * unset and every submitted value is valid for its reference field definition — otherwise
	 * NeedsReview is returned again with the offending codes (the UI is not the source of
	 * truth). Re-validation runs before the transaction, so a rejected submit leaves the
	 * previous version and constants untouched.
	 *
	 * @param int $launchedId Launched copy id.
	 * @param array<string, mixed>|null $providedConstantValues Submitted constant values keyed by
	 *   code. Null marks the first call (show the review master); a non-null array (even empty)
	 *   marks a submit and triggers the apply path subject to re-validation.
	 */
	public function upgrade(int $launchedId, ?array $providedConstantValues = null): AgentUpgradeResult
	{
		$copy = $this->loadLaunchedCopy($launchedId);
		if ($copy === null)
		{
			return $this->journaled(
				$launchedId,
				AgentUpgradeResult::conflict(
					new Error('Launched copy not found', self::ERR_REFERENCE_UNAVAILABLE),
				),
			);
		}

		$originSystemCode = $this->aiAgentRepository->getOriginSystemCode($launchedId);
		if ($originSystemCode === null)
		{
			return $this->journaled(
				$launchedId,
				AgentUpgradeResult::conflict(
					new Error('Launched copy is not linked to a system template', self::ERR_NO_ORIGIN),
				),
			);
		}

		$system = $this->loadSystemTemplateByCode($originSystemCode);
		if ($system === null)
		{
			return $this->journaled(
				$launchedId,
				AgentUpgradeResult::conflict(
					new Error('Reference system template is unavailable', self::ERR_REFERENCE_UNAVAILABLE),
				),
			);
		}

		$newRevision = $this->templateRevisionService->calculateRevision($system['TEMPLATE']);
		$installedVersion = $this->aiAgentRepository->getOriginSystemVersion($launchedId);
		if ($newRevision === $installedVersion)
		{
			return $this->journaled(
				$launchedId,
				AgentUpgradeResult::conflict(
					new Error('Agent is already up to date', self::ERR_ALREADY_UP_TO_DATE),
				),
			);
		}

		$merge = $this->constantMergeService->merge(
			(array)($system['CONSTANTS'] ?? []),
			(array)($copy['CONSTANTS'] ?? []),
		);

		$targetConstants = $this->applyProvidedValues($merge->target, $providedConstantValues ?? []);
		$stillMissing = $this->stillMissingRequired($merge->newRequiredMissing, $targetConstants);

		// Re-validate the values that are about to be written against the reference (new version) setup
		// blocks before the transaction: a value invalid for its field definition re-shows the review
		// master and is never written, unlike the post-apply best-effort fill(). The whole target set of
		// values is checked, not the submitted part of it: the rest arrives from the merge with the previous
		// values and a new field definition of the reference may well refuse it. The blocks come from the
		// first setup screen of the reference only, so constants defined by its further screens stay
		// unchecked (SDD Q-1). Empty values are covered by $stillMissing above, so this reports only
		// non-empty values that fail their field type.
		$invalidConstants = $providedConstantValues === null
			? []
			: $this->setupTemplateService->collectInvalidConstantValues(
				(int)$system['ID'],
				$this->extractCurrentValues($targetConstants),
			)
		;

		// Show the review master on the first call, or re-show it when a submit still leaves a
		// required constant unset or carries an invalid value. Nothing is persisted until an
		// apply-time submit passes re-validation. The transport renders the reference blocks from
		// the reference id and pre-fills them with the current values.
		if ($providedConstantValues === null || $stillMissing !== [] || $invalidConstants !== [])
		{
			return $this->journaled(
				$launchedId,
				AgentUpgradeResult::needsReview(
					$this->extractCurrentValues($targetConstants),
					$stillMissing,
					(int)$system['ID'],
					$invalidConstants,
				),
			);
		}

		return $this->journaled(
			$launchedId,
			$this->applyUpgrade($launchedId, $system['TEMPLATE'], $targetConstants, $newRevision),
		);
	}

	/**
	 * Fills provided values into the target constants' Default by code. Only codes
	 * already present in the target are honoured; unknown codes are ignored so the
	 * fill scenario cannot inject constants the new reference does not define.
	 *
	 * @param array<string, mixed> $providedValues
	 */
	private function applyProvidedValues(array $target, array $providedValues): array
	{
		foreach ($providedValues as $code => $value)
		{
			if (array_key_exists($code, $target))
			{
				$target[$code]['Default'] = $value;
			}
		}

		return $target;
	}

	/**
	 * @param list<string> $newRequiredMissing
	 * @return list<string>
	 */
	private function stillMissingRequired(array $newRequiredMissing, array $target): array
	{
		$missing = [];
		foreach ($newRequiredMissing as $code)
		{
			if (\CBPHelper::isEmptyValue($target[$code]['Default'] ?? null))
			{
				$missing[] = $code;
			}
		}

		return $missing;
	}

	/**
	 * Flattens the target constant set to a code => current-value map for pre-filling the
	 * review master. Values reflect the merge (copy values carried over onto the reference
	 * definitions) with any submitted values applied on top; on the first call they equal
	 * the merge target.
	 *
	 * @return array<string, mixed>
	 */
	private function extractCurrentValues(array $targetConstants): array
	{
		$values = [];
		foreach ($targetConstants as $code => $definition)
		{
			$values[$code] = $definition['Default'] ?? null;
		}

		return $values;
	}

	/**
	 * Atomic write inside a transaction: replaces the copy logic (TEMPLATE), persists the
	 * merged target constants and records the new installed version. Any failure rolls the
	 * transaction back, leaving the previous logic, constants and version intact (ERR-005).
	 * Reference availability and revision are validated earlier in upgrade(), outside this
	 * transaction; they are not re-read here.
	 */
	private function applyUpgrade(
		int $launchedId,
		array $newTemplate,
		array $targetConstants,
		string $newRevision,
	): AgentUpgradeResult
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$updated = \CBPWorkflowTemplateLoader::update(
				$launchedId,
				[
					'TEMPLATE' => $newTemplate,
					'CONSTANTS' => $targetConstants,
				],
				systemImport: true,
			);

			if ((int)$updated !== $launchedId)
			{
				throw new \RuntimeException('Template update did not return the expected id');
			}

			$this->aiAgentRepository->saveOriginSystemVersion($launchedId, $newRevision);
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			return AgentUpgradeResult::failed(
				new Error($e->getMessage(), self::ERR_APPLY_FAILED),
			);
		}

		$connection->commitTransaction();

		return AgentUpgradeResult::updated($newRevision);
	}

	/**
	 * @return array{ID: int, TEMPLATE: array, CONSTANTS: array}|null
	 */
	private function loadLaunchedCopy(int $launchedId): ?array
	{
		if ($launchedId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['ID', 'TEMPLATE', 'CONSTANTS'])
			->where('ID', $launchedId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE') // launched copy only
			->setLimit(1)
			->fetch()
		;

		if (!$row || empty($row['TEMPLATE']))
		{
			return null;
		}

		return [
			'ID' => (int)$row['ID'],
			'TEMPLATE' => (array)$row['TEMPLATE'],
			'CONSTANTS' => (array)($row['CONSTANTS'] ?? []),
		];
	}

	/**
	 * @return array{ID: int, TEMPLATE: array, CONSTANTS: array}|null
	 */
	private function loadSystemTemplateByCode(string $systemCode): ?array
	{
		$row = WorkflowTemplateTable::query()
			->setSelect(['ID', 'TEMPLATE', 'CONSTANTS'])
			->where('SYSTEM_CODE', $systemCode)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->setLimit(1)
			->fetch()
		;

		if (!$row || empty($row['TEMPLATE']))
		{
			return null;
		}

		return [
			'ID' => (int)$row['ID'],
			'TEMPLATE' => (array)$row['TEMPLATE'],
			'CONSTANTS' => (array)($row['CONSTANTS'] ?? []),
		];
	}

	/**
	 * Records a terminal upgrade outcome for observability (NFR): Updated at INFO,
	 * Conflict/Failed at WARNING. NeedsReview is not a terminal outcome (the review
	 * master is opened on the first call and on every re-validation), so it is not
	 * journaled, to keep the event log free of that noise. A journaling failure must
	 * never roll back an already-applied upgrade, so it is best-effort and swallows
	 * its own errors.
	 */
	private function journaled(int $launchedId, AgentUpgradeResult $result): AgentUpgradeResult
	{
		if ($result->getStatus() === UpgradeStatus::NeedsReview)
		{
			return $result;
		}

		try
		{
			$isSuccess = $result->getStatus() === UpgradeStatus::Updated;

			$message = sprintf(
				'AI-agent upgrade attempt: templateId=%d status=%s%s',
				$launchedId,
				$result->getStatus()->value,
				$isSuccess ? '' : ' errors=' . implode('; ', $result->getErrorMessages()),
			);

			\CEventLog::Log(
				$isSuccess ? 'INFO' : 'WARNING',
				self::AUDIT_TYPE,
				'bizproc',
				(string)$launchedId,
				$message,
			);
		}
		catch (\Throwable)
		{
			// Observability is best-effort; never affect the upgrade outcome.
		}

		return $result;
	}
}
