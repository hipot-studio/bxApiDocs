<?php
declare(strict_types=1);

namespace Bitrix\Landing\Site;

use Bitrix\Landing\Rights;

/**
 * Helper for saving and restoring Landing scope and rights during specific.
 *
 * Usage:
 *  $guard = new ScopeGuard();
 *  $guard->start();
 *
 * After that, all operations that require special scope will be executed in the required scope.
 * Scope and rights will be restored automatically on object destruction
 * (end of scope) or explicitly by calling stop().
 */
final class ScopeGuard
{
	private string $scope;
	private bool $started = false;
	private ?string $scopeBefore = null;
	private bool $localRightsBefore = false;
	private bool $globalRightsBefore = false;

	public function __construct(string $scope)
	{
		$this->scope = $scope;
	}

	/**
	 * Explicitly starts the guard: stores current stat and sets scope/rights.
	 */
	public function start(): void
	{
 		if ($this->started)
		{
			return;
		}

		$this->started = true;

		$this->scopeBefore = Type::getCurrentScopeId();
		$this->localRightsBefore = Rights::isLocalOn();
		$this->globalRightsBefore = Rights::isGlobalOn();

		Type::setScope($this->scope);
		Rights::setOff();
		Rights::setGlobalOff();
	}

	/**
	 * Explicitly stops the guard and restores scope/rights.
	 * Can be called manually; will also be called automatically on destruction.
	 */
	public function stop(): void
	{
		if (!$this->started)
		{
			return;
		}

		if ($this->scopeBefore === null)
		{
			Type::clearScope();
		}
		elseif (
			is_string($this->scopeBefore)
			&& $this->scopeBefore !== $this->scope
		)
		{
			Type::setScope($this->scopeBefore);
		}

		// each flag goes back to its own value: the composite isOn() answers false under
		// LANDING_DISABLE_RIGHTS or a nested guard and would leave both flags off for good
		if ($this->localRightsBefore)
		{
			Rights::setOn();
		}
		if ($this->globalRightsBefore)
		{
			Rights::setGlobalOn();
		}

		$this->started = false;
	}

	public function __destruct()
	{
		$this->stop();
	}
}

