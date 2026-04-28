<?php
declare(strict_types=1);

namespace Bitrix\Landing\Scope\Guard;

use Bitrix\Landing\Rights;
use Bitrix\Landing\Site\Type;

/**
 * Helper for saving and restoring Landing scope and rights for Vibe operations.
 *
 * Usage:
 *  $guard = new Guard\Vibe();
 *  $guard->start();
 *
 * After that, all operations that require Vibe scope will be executed in the required scope.
 * Scope and rights will be restored automatically on object destruction
 * (end of scope) or explicitly by calling stop().
 */
final class Vibe
{
	private bool $started = false;
	private ?string $scopeBefore = null;
	private bool $rightsBefore = false;

	/**
	 * Explicitly starts the guard: stores current state
	 * and sets scope/rights for Vibe.
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

		Type::setScope(Type::SCOPE_CODE_VIBE);
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
			&& $this->scopeBefore !== Type::SCOPE_CODE_VIBE
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

