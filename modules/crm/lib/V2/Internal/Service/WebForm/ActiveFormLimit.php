<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\V2\Internal\Integration\Bitrix24\WebFormWriteRestriction;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository;

/**
 * How many forms the portal keeps working and how many it may keep working.
 *
 * One object answers both sides of that question: the scenarios refusing to add another working
 * form ({@see FormWriter::add()}, {@see FormActivator::setActive()}) and the dictionary publishing
 * the pair to the client ({@see \Bitrix\Crm\V2\Internal\Repository\WebForm\FormConfigurationRepository})
 * would otherwise compare and publish numbers that can drift apart, and a client would be shown one
 * limit while being refused by another.
 *
 * Whether the limit applies at all is a question of the plan of the portal and is answered where the
 * questions of the plan live: outside the cloud nothing is counted, the way the product turns forms
 * on without a count ({@see \Bitrix\Crm\WebForm\Form::canActivateForm()}).
 *
 * Nothing is remembered between the calls: the count is a fact of the moment, and a scenario asking
 * twice asks about the portal as it is now.
 *
 * @internal
 */
final class ActiveFormLimit
{
	private readonly \Closure $count;
	private readonly \Closure $maximum;
	private readonly WebFormWriteRestriction $restriction;

	/**
	 * @param null|\Closure(): int $count
	 * @param null|\Closure(): int $maximum
	 */
	public function __construct(
		?\Closure $count = null,
		?\Closure $maximum = null,
		?WebFormWriteRestriction $restriction = null,
	)
	{
		$this->count = $count ?? static fn (): int => (new FormRepository())->getActiveFormCount();
		$this->maximum = $maximum ?? static fn (): int => (new FormRepository())->getActiveFormMaximum();
		$this->restriction = $restriction ?? new WebFormWriteRestriction();
	}

	/** Tells whether the number of working forms is limited on this portal at all. */
	public function isApplied(): bool
	{
		return $this->restriction->isActivationLimited();
	}

	/**
	 * Tells whether the portal already keeps as many forms working as its plan allows.
	 *
	 * A portal with no plan to be limited by is never counted: the question of the plan comes first
	 * and nothing is asked of the storage after it answers no.
	 */
	public function isReached(): bool
	{
		return $this->isApplied() && ($this->count)() >= ($this->maximum)();
	}

	/**
	 * The pair a client is shown, and nothing on a portal where turning a form on is not limited.
	 *
	 * @return null|array{current: int, maximum: int}
	 */
	public function describe(): ?array
	{
		if (!$this->isApplied())
		{
			return null;
		}

		return [
			'current' => ($this->count)(),
			'maximum' => ($this->maximum)(),
		];
	}
}
