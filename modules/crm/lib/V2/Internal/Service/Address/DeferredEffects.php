<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

/**
 * The side effects of a write that must not happen while the write is still open.
 *
 * A write of an address is one transaction, and the work it causes beyond the storage - reindexing the
 * duplicate criteria, dropping the aggregate caches, publishing the domain events - has nothing to do
 * inside that transaction. Subscribers of those events are foreign code, part of it outside the crm
 * module, and running them under the row locks of an unfinished write means they hold the connection and
 * read data nobody has committed yet.
 *
 * {@see AddressEffects} is where the effects of the address row itself live, and the write operation calls
 * it after the boundary has closed. The effects collected here are the ones a caller cannot place: they
 * belong to code the address write only reaches through somebody else - the requisite the row lands on and
 * the reverse synchronization of the geodata, which is an orm event handler nothing can hand an argument
 * to. Each of those places wraps its effects into a closure and passes it here instead of running it, and
 * this class decides when the closure runs:
 *
 * - inside a write of an address - the only place opening a scope, {@see OwnerAddressService} - the effects
 *   are collected and run once the boundary of the write has closed, in the order they were deferred in;
 * - anywhere else - and that is every other consumer of those places in the module - there is no scope, and
 *   the closure runs on the spot, exactly where the code used to run it.
 *
 * The effects of a write that was rolled back are dropped together with the scope: an operation that did
 * not happen announces nothing, which is the rule the effects of the address row already follow.
 *
 * A write reached from inside another write gets a scope of its own, and its effects run when its own
 * boundary closes - right after the write and still inside the transaction of the caller, which is where
 * the effects of a nested write have always run.
 *
 * @internal
 */
final class DeferredEffects
{
	private static ?self $current = null;

	private ?self $previous = null;

	/** @var array<int, callable> */
	private array $effects = [];

	/**
	 * Opens a scope and makes it the one effects are collected in until it is closed.
	 */
	public static function open(): self
	{
		$scope = new self();
		$scope->previous = self::$current;
		self::$current = $scope;

		return $scope;
	}

	/**
	 * Runs the effect after the boundary of the address write in progress has closed, or right away when
	 * no write is in progress.
	 */
	public static function defer(callable $effect): void
	{
		if (self::$current === null)
		{
			$effect();

			return;
		}

		self::$current->effects[] = $effect;
	}

	/**
	 * Stops collecting. The effects gathered so far stay with the scope, so the caller may still run them -
	 * and they run outside the scope, which is the point of closing it first.
	 */
	public function close(): void
	{
		self::$current = $this->previous;
		$this->previous = null;
	}

	/**
	 * Runs the collected effects in the order they were deferred in, once.
	 */
	public function run(): void
	{
		$effects = $this->effects;
		$this->effects = [];

		foreach ($effects as $effect)
		{
			$effect();
		}
	}
}
