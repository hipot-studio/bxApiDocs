<?php

namespace Bitrix\BizprocDesigner\Internal\Config;

use Bitrix\Bizproc\Public\Feature\Node\PoolNodeFlag;
use Bitrix\Bizproc\Internal\Service\Feature\AiAgentsFeature;
use Bitrix\Bizproc\Internal\Service\ProcessData\ProcessDataFeature;
use Bitrix\Bizproc\Public\Feature\DataView\DataViewFlag;
use Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityService;
use Bitrix\Bizproc\Public\Service\LastValues\LastValuesService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotEditorStateService;
use Bitrix\BizprocDesigner\Public\Feature\Ai\AiAssistantFlag;
use Bitrix\BizprocDesigner\Public\Feature\Ai\ExternalAiAgentFlag;
use Bitrix\BizprocDesigner\Public\Feature\Editor\DebugBarFlag;
use Bitrix\BizprocDesigner\Public\Feature\Editor\RulesSummaryFlag;
use Bitrix\BizprocDesigner\Public\Feature\Editor\TemplateNotesFlag;
use Bitrix\BizprocDesigner\Public\Feature\Expression\ExpressionBuilderFlag;
use Bitrix\BizprocDesigner\Public\Feature\Expression\ReadableExpressionsFlag;
use Bitrix\BizprocDesigner\Public\Feature\Node\ComplexNodeConnectionsFlag;
use Bitrix\BizprocDesigner\Public\Feature\Node\NodeFilterFlag;
use Bitrix\BizprocDesigner\Public\Feature\Template\TemplateEditLockFlag;
use Bitrix\BizprocDesigner\Public\Feature\Template\VersionHistoryUiFlag;
use Bitrix\BizprocDesigner\Public\Features\ComponentLibrary;
use Bitrix\BizprocDesigner\Public\Features\TestRunFeature;
use Bitrix\Main;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\BizprocDesigner\Internal\Trait\SingletonTrait;
use Bitrix\Main\ObjectNotFoundException;

class Feature
{
	use SingletonTrait;

	private const MODULE_NAME = 'bizprocdesigner';

	public function isAiAssistantAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(AiAssistantFlag::class);
	}

	public function isExternalAiAgentAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(ExternalAiAgentFlag::class);
	}

	public function areComplexNodeConnectionsAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(ComplexNodeConnectionsFlag::class);
	}

	public function isDebugBarAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(DebugBarFlag::class);
	}

	/**
	 * The test run of a template from a trigger node lives inside the debugger of the editor: the run is
	 * watched by its journal, so it needs the debugger besides its own flag.
	 */
	public function isTestRunAvailable(): bool
	{
		return $this->isDebugBarAvailable() && Main\Config\Feature::isEnabled(TestRunFeature::class);
	}

	/**
	 * Closes only the history interface: capturing the versions themselves is governed by the bizproc feature
	 * flag \Bitrix\Bizproc\Public\Feature\Template\TemplateVersionHistoryFlag and is switched on earlier.
	 */
	public function isVersionHistoryAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(VersionHistoryUiFlag::class);
	}

	/**
	 * Covers the editing lease and the draft conflict guard. The editor passes the state to bizproc in each
	 * write request, so bizproc keeps no dependency on this module or its feature flag
	 * \Bitrix\BizprocDesigner\Public\Feature\Template\TemplateEditLockFlag.
	 */
	public function isTemplateEditLockAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(TemplateEditLockFlag::class);
	}

	public function isNodeFilterAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(NodeFilterFlag::class);
	}

	/**
	 * Closes the whole server surface of the note discussions, not only the controller: the hint of the
	 * mentions answers an empty result while it is off, so a disabled feature is refused on the server
	 * and not merely hidden in the interface.
	 */
	public function areTemplateNotesAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(TemplateNotesFlag::class);
	}

	/**
	 * Closes the whole surface of the library of the reusable assemblies: both the entry points of the
	 * client and every action of its controller, so a disabled feature is refused on the server and not
	 * merely hidden in the interface (DTO-04).
	 *
	 * The only flag of this registry kept in the package of the main module rather than in an option of the
	 * editor: the feature is new, and a new flag belongs to the mechanism the repository moved to
	 * ({@see \Bitrix\Main\Config\Feature}, main 26.700.0 and up). What the neighbours above answer by is not
	 * touched - migrating them is the business of their own tasks. The registry of the codes stays the one
	 * place that turns the verdict into the `componentLibrary` code of the client, so nothing but this line
	 * knows where the verdict comes from.
	 */
	public function isComponentLibraryAvailable(): bool
	{
		return Main\Config\Feature::isEnabled(ComponentLibrary::class);
	}

	public function areDataTablesAvailable(): bool
	{
		return Main\Config\Feature::isEnabled(DataViewFlag::class);
	}

	/**
	 * The window of the designer and the server rules of the labels are one feature flag: bizproc is asked,
	 * the state is not repeated here.
	 */
	public function isProcessDataAvailable(): bool
	{
		return ProcessDataFeature::isEnabled();
	}

	/**
	 * The node of the pool belongs to bizproc together with its activity, so the flag of that module is
	 * asked instead of being repeated by an option of the editor.
	 */
	public function isPoolNodeAvailable(): bool
	{
		return Main\Config\Feature::isEnabled(PoolNodeFlag::class);
	}

	public function isExpressionBuilderAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(ExpressionBuilderFlag::class);
	}

	public function isReadableExpressionsAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(ReadableExpressionsFlag::class);
	}

	/**
	 * Closes only the summary of the node settings shown by the settings panel: the providers and the
	 * content block of the canvas are resolved regardless of the flag.
	 */
	public function isRulesSummaryAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(RulesSummaryFlag::class);
	}

	/**
	 * The editor has no flag of its own here: showing the values of the last run makes sense only while
	 * bizproc really captures them, so both sides follow the single feature flag of that module:
	 * \Bitrix\Bizproc\Public\Feature\Template\LastValuesCaptureFlag.
	 */
	public function isLastRunValuesAvailable(): bool
	{
		return Main\Loader::includeModule('bizproc') && (new LastValuesService())->isAvailable();
	}

	/**
	 * The editor has no flag of its own here either: the choice of the audience may only be offered while
	 * the module of the processes really accepts a pilot publication, so both sides follow the single
	 * source of that feature flag instead of duplicating the state here.
	 */
	public function isPilotPublicationAvailable(): bool
	{
		return Main\Loader::includeModule('bizproc') && (new PilotEditorStateService())->isFeatureEnabled();
	}

	public function getAgentTokenTtl(): int
	{
		$ttl = (int)Main\Config\Option::get(self::MODULE_NAME, 'agent_token_ttl', 86400);

		// A non-positive TTL would make the token expire at or before creation (expire_at <= now),
		// breaking the whole lifecycle; fall back to the safe default for such invalid overrides.
		if ($ttl <= 0)
		{
			return 86400;
		}

		return $ttl;
	}

	/**
	 * @return list<string>
	 */
	public function getAvailableFeatureCodes(): array
	{
		$featureCodes = [];
		if ($this->isAiAssistantAvailable())
		{
			$featureCodes[] = 'aiAssistant';
		}

		if ($this->areComplexNodeConnectionsAvailable())
		{
			$featureCodes[] = 'complexNodeConnections';
		}

		if ($this->isDebugBarAvailable())
		{
			$featureCodes[] = 'debugBar';
		}

		if ($this->isTestRunAvailable())
		{
			$featureCodes[] = 'testRun';
		}

		if ($this->areDataTablesAvailable())
		{
			$featureCodes[] = 'dataTables';
		}

		if ($this->isExternalAiAgentAvailable())
		{
			$featureCodes[] = 'externalAiAgent';
		}

		if ($this->isExpressionBuilderAvailable())
		{
			$featureCodes[] = 'expressionBuilder';
		}

		if ($this->isReadableExpressionsAvailable())
		{
			$featureCodes[] = 'readableExpressions';
		}

		if ($this->isLastRunValuesAvailable())
		{
			$featureCodes[] = 'lastRunValues';
		}

		if ($this->isVersionHistoryAvailable())
		{
			$featureCodes[] = 'versionHistory';
		}

		if ($this->isTemplateEditLockAvailable())
		{
			$featureCodes[] = 'templateEditLock';
		}

		if ($this->isPilotPublicationAvailable())
		{
			$featureCodes[] = 'pilotPublication';
		}

		if ($this->areTemplateNotesAvailable())
		{
			$featureCodes[] = 'templateNotes';
		}

		if ($this->isRulesSummaryAvailable())
		{
			$featureCodes[] = 'rulesSummary';
		}

		if ($this->isNodeFilterAvailable())
		{
			$featureCodes[] = 'nodeFilter';
		}

		if ($this->isProcessDataAvailable())
		{
			$featureCodes[] = 'processData';
		}

		if ($this->isComponentLibraryAvailable())
		{
			$featureCodes[] = 'componentLibrary';
		}

		// The editor gates nothing by this code: the pool leaves the palette on the server, through
		// EXCLUDED of its activity. It is published as the one client-readable sign of the rollout,
		// which is how the e2e of the catalog tells the state of the stand before it asserts anything.
		if ($this->isPoolNodeAvailable())
		{
			$featureCodes[] = 'poolNode';
		}

		return $featureCodes;
	}

	/**
	 * @return list<string>
	 */
	public function getLockedFeatureCodes(): array
	{
		$lockedCodes = [];

		if ($this->isExternalAiAgentAvailable() && !$this->isExternalAiAgentUnlocked())
		{
			$lockedCodes[] = 'externalAiAgent';
		}

		return $lockedCodes;
	}

	/**
	 * The single server-side gate of the external AI agent feature: its feature flag is enabled AND
	 * the feature is unlocked for the portal (AI tariff + region). The REST availability filter and the
	 * agent-token issuance controller call it so a disabled feature is refused on the server, not merely
	 * hidden in the UI.
	 *
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function isExternalAiAgentAccessible(): bool
	{
		return $this->isExternalAiAgentAvailable() && $this->isExternalAiAgentUnlocked();
	}

	/**
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	private function isExternalAiAgentUnlocked(): bool
	{
		try
		{
			$aiAgentsFeature = ServiceLocator::getInstance()->get(AiAgentsFeature::class);
		}
		catch (ServiceNotFoundException)
		{
			$aiAgentsFeature = null;
		}

		if ($aiAgentsFeature !== null && !$aiAgentsFeature->isAvailable())
		{
			return false;
		}

		try
		{
			$regionService = ServiceLocator::getInstance()->get(RegionAvailabilityService::class);
		}
		catch (ServiceNotFoundException)
		{
			$regionService = null;
		}

		if ($regionService !== null && !$regionService->isAvailable())
		{
			return false;
		}

		return true;
	}
}
