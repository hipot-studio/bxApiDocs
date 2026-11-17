<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Bizproc;

use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotVersionResolver;

/**
 * The parameters of the start form of a template for the employee about to start it: the ones of the pilot
 * version when a pilot of bizproc acts for this employee, the ones of the live row otherwise.
 *
 * The version is asked of the public resolver of bizproc only. A read naming no surface, template or initiator
 * asks nothing: it is no manual start, and the question would be counted by bizproc as a start without a mark.
 */
final class PilotStartFormParameters
{
	private readonly \Closure $resolverFactory;
	private ?object $resolver = null;

	/**
	 * @param \Closure|null $resolverFactory gives an object with resolve(int, int, string); the public resolver of
	 *     bizproc by default
	 */
	public function __construct(
		private readonly ManualStartCapabilities $capabilities,
		?\Closure $resolverFactory = null,
	)
	{
		$this->resolverFactory = $resolverFactory ?? static fn(): object => new PilotVersionResolver();
	}

	/**
	 * @param array $commonParameters PARAMETERS of the live template row
	 *
	 * @return array PARAMETERS of the version acting for this employee
	 */
	public function forInitiator(int $templateId, int $initiatorId, ?string $surface, array $commonParameters): array
	{
		if ($surface === null || $templateId <= 0 || $initiatorId <= 0 || !$this->capabilities->hasPilot())
		{
			return $commonParameters;
		}

		$choice = $this->getResolver()->resolve($templateId, $initiatorId, $surface);
		$executableFields = $choice->executableFields ?? null;
		if ($executableFields === null)
		{
			return $commonParameters;
		}

		$pilotParameters = $executableFields['PARAMETERS'] ?? [];

		return is_array($pilotParameters) ? $pilotParameters : [];
	}

	private function getResolver(): object
	{
		$this->resolver ??= ($this->resolverFactory)();

		return $this->resolver;
	}
}
