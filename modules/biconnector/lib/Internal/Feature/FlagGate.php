<?php

namespace Bitrix\BIConnector\Internal\Feature;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\Context;

/**
 * Answers for the delivery flags of the builder on behalf of an entry point. Operations never
 * read a flag: the entry point asks here and hands them a ready policy.
 *
 * Answers "off" when the kernel has no flag API, the flag class is gone or fails to load, unlike
 * the kernel, which treats a removed flag as enabled.
 */
class FlagGate
{
	// Referenced by name to keep Internal free of Public, as in SqlLabRestGate.
	public const BUILDER_MCP_OPERATIONS = 'Bitrix\\BIConnector\\Public\\Features\\BuilderMcpOperations';
	public const BUILDER_MCP_ALIGNMENT = 'Bitrix\\BIConnector\\Public\\Features\\BuilderMcpAlignment';
	public const BUILDER_REST = 'Bitrix\\BIConnector\\Public\\Features\\BuilderRest';
	public const REST_SOURCE_DIAGNOSTICS = 'Bitrix\\BIConnector\\Public\\Features\\RestSourceDiagnostics';
	public const BUILDER_AGENT_SKILL = 'Bitrix\\BIConnector\\Public\\Features\\BuilderAgentSkill';

	private readonly FeatureFlagChecker $flagChecker;

	public function __construct(?FeatureFlagChecker $flagChecker = null)
	{
		$this->flagChecker = $flagChecker ?? new FeatureFlagChecker();
	}

	public function isEnabled(string $flag, int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		// Class checks autoload, so a broken flag file must refuse here too.
		try
		{
			if (!$this->flagChecker->isClassAvailable(Feature::class))
			{
				return false;
			}

			if (
				!$this->flagChecker->isClassAvailable(AbstractFlag::class)
				|| !$this->flagChecker->isClassAvailable($flag)
				|| !is_subclass_of($flag, AbstractFlag::class)
			)
			{
				return false;
			}

			return $this->flagChecker->isEnabled($flag, new Context($userId));
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
