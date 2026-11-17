<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Bizproc;

use Bitrix\Bizproc\Public\Provider\PilotVisibilityProvider;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\ManualStartPolicyService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotVersionResolver;
use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;
use Bitrix\Main\Loader;

/**
 * What the installed bizproc offers to a manual start of a workflow from disk. The update of disk declares no
 * dependency on bizproc, so a portal may run a bizproc older than the symbols the start uses; this is the single
 * place that asks for them, and a test replaces it to play an older bizproc.
 *
 * - the mark of the surface of a manual start (bizproc 26.1400.0): the enum and the parameter of the same name
 *   in ContextDto, PrepareStartParametersRequest and CBPDocument;
 * - the pilot of a template (bizproc 26.1400.0): the visibility filter and the resolver of the acting version;
 * - the launch rights model (bizproc 26.2100.0), which is built on the mark of the surface.
 */
class ManualStartCapabilities
{
	private ?bool $hasManualStartSurface = null;
	private ?bool $hasPilot = null;
	private ?bool $hasManualStartPolicy = null;

	public function hasManualStartSurface(): bool
	{
		$this->hasManualStartSurface ??= $this->isBizprocIncluded() && enum_exists(ManualStartSurface::class);

		return $this->hasManualStartSurface;
	}

	public function hasPilot(): bool
	{
		$this->hasPilot ??= $this->isBizprocIncluded()
			&& class_exists(PilotVisibilityProvider::class)
			&& class_exists(PilotVersionResolver::class)
		;

		return $this->hasPilot;
	}

	public function hasManualStartPolicy(): bool
	{
		$this->hasManualStartPolicy ??= $this->isBizprocIncluded()
			&& class_exists(ManualStartPolicyService::class)
			&& $this->hasManualStartSurface()
		;

		return $this->hasManualStartPolicy;
	}

	private function isBizprocIncluded(): bool
	{
		return Loader::includeModule('bizproc');
	}
}
