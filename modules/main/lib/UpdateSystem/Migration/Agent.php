<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\UpdateSystem\Migration\Tools\SourceCode;

class Agent
{
	private readonly string $CAgentClass;

	public function __construct(
		private readonly Context $context,
		?string $cAgentClass = null,
	)
	{
		$this->CAgentClass = $cAgentClass ?? \CAgent::class;
	}

	/**
	 * Add agent. Checks if canUpdateDatabase and isModuleInstalled.
	 *
	 * @param string|array{class-string, string} $agentName Either an invocation string `'Foo::bar();'`
	 *     or a class+method tuple `[Foo::class, 'bar']`. The tuple form keeps the method name navigable
	 *     in IDEs without triggering autoload of the target class at migration time.
	 * @param int|null $execDelay First execution delay in seconds.
	 * @throws Exception
	 */
	public function add(string|array $agentName, int $interval, bool $isPeriod = false, ?int $execDelay = null): self
	{
		$mode = $this->context->getDatabaseUpdateMode();
		if (!$mode->canUpdateDatabase())
		{
			return $this;
		}

		// ModuleInstall bypasses the `isModuleInstalled()` guard because the module
		// registry entry is not yet written when install migrations run.
		if (!$mode->isModuleInstall() && !$this->context->isModuleInstalled())
		{
			return $this;
		}

		return $this->doAdd($this->normalizeAgentName($agentName), $interval, $isPeriod, $execDelay);
	}

	/**
	 * @param string|array{class-string, string} $agentName
	 * @param int|null $execDelay First execution delay in seconds.
	 * @throws Exception
	 */
	public function addIfModuleInstalled(string|array $agentName, int $interval, bool $isPeriod = false, ?int $execDelay = null): self
	{
		return $this->add($agentName, $interval, $isPeriod, $execDelay);
	}

	/**
	 * Add agent. Checks if canUpdateDatabase and moduleTablesExist.
	 *
	 * @param string|array{class-string, string} $agentName
	 * @param int|null $execDelay First execution delay in seconds.
	 * @throws Exception
	 */
	public function addIfModuleExists(string|array $agentName, int $interval, bool $isPeriod = false, ?int $execDelay = null): self
	{
		if (!$this->context->getDatabaseUpdateMode()->canUpdateDatabase())
		{
			return $this;
		}

		if (!$this->context->moduleTablesExist())
		{
			return $this;
		}

		return $this->doAdd($this->normalizeAgentName($agentName), $interval, $isPeriod, $execDelay);
	}

	/**
	 * @param string|array{class-string, string} $agentName
	 * @throws Exception
	 */
	public function remove(string|array $agentName): self
	{
		$mode = $this->context->getDatabaseUpdateMode();
		if (!$mode->canUpdateDatabase())
		{
			return $this;
		}

		// Module install never removes — there is nothing wired up yet.
		if ($mode->isModuleInstall())
		{
			return $this;
		}

		$agentName = $this->normalizeAgentName($agentName);

		if (
			$this->context->isDevMode()
			&& SourceCode::classBelongsToModule($agentName, $this->context->getModuleId()) === false
		)
		{
			throw new Exception(
				$this->context->getModuleId(),
				200,
				'Agent should belong to module',
			);
		}

		$cAgent = $this->CAgentClass;
		$cAgent::RemoveAgent($agentName, $this->context->getModuleId());

		return $this;
	}

	/**
	 * @param string|array{class-string, string} $agentName
	 */
	private function normalizeAgentName(string|array $agentName): string
	{
		if (is_string($agentName))
		{
			return $agentName;
		}

		[$class, $method] = $agentName;

		return $class . '::' . $method . '();';
	}

	private function doAdd(string $agentName, int $interval, bool $isPeriod = false, ?int $execDelay = null): self
	{
		if (
			$this->context->isDevMode()
			&& str_starts_with($agentName, '\\')
		)
		{
			throw new Exception(
				$this->context->getModuleId(),
				201,
				'Agent name should not start with backslash',
			);
		}
		if (
			$this->context->isDevMode()
			&& SourceCode::classBelongsToModule($agentName, $this->context->getModuleId()) === false
		)
		{
			throw new Exception(
				$this->context->getModuleId(),
				200,
				'Agent should belong to module',
			);
		}

		$delay = 60; // 1 minute by default to avoid race condition

		if ($this->context->isCloud())
		{
			$delay = 60 * 10;
		}
		elseif (defined('BX_PRODUCT_INSTALLATION') && BX_PRODUCT_INSTALLATION === true) // distr installation
		{
			$delay = 60 * 15;
		}
		elseif ($this->context->getDatabaseUpdateMode() === DatabaseUpdateMode::ModuleInstall)  // module installation from an updater
		{
			$delay = 60;
		}

		$delay = max($delay ?? 0, $execDelay ?? 0);

		$nextExec = ($delay > 0)
			? \ConvertTimeStamp(time() + \CTimeZone::GetOffset() + $delay, 'FULL')
			: ''
		;

		$CAgent = $this->CAgentClass;
		$CAgent::AddAgent(
			$agentName,
			$this->context->getModuleId(),
			$isPeriod ? 'Y' : 'N',
			$interval,
			'',
			'Y',
			$nextExec,
			100,
			false,
			false,
		);

		return $this;
	}
}
