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
	private bool $rightsBefore = false;

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
		$this->rightsBefore = Rights::isOn();

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

		if ($this->rightsBefore)
		{
			Rights::setOn();
			Rights::setGlobalOn();
		}

		$this->started = false;
	}

	public function __destruct()
	{
		$this->stop();
	}
}

