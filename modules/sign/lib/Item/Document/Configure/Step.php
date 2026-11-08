<?php

namespace Bitrix\Sign\Item\Document\Configure;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;

/**
 * One document change of the configure plan: the name it is journaled under, whether it can
 * be rolled back, and the action that applies it.
 *
 * @see Plan holds the steps in their apply order.
 */
final class Step implements Contract\Item
{
	public const REPRESENTATIVE = 'representative';
	public const COMPANY = 'company';
	public const PROVIDER = 'provider';
	public const MEMBERS = 'members';
	public const DOCUMENT_CODE = 'documentCode';
	public const EXTERNAL_ID = 'externalId';
	public const DATE_SIGN_UNTIL = 'dateSignUntil';

	/**
	 * The steps whose apply writes the member composition. The member step is not the only one
	 * that writes it: the company member takes the communications of the representative from the
	 * same domain path, so a run changing the representative alone writes the composition under
	 * the name of the representative step. The company step writes it too, because the company
	 * member points at the company of the document and moves with it; a run naming the company
	 * alone plans no member step, and without this the member and the card of the smart process
	 * would keep the company the document no longer has.
	 *
	 * The only public constant of the class that is not a name of a step: a reader enumerating them
	 * as names has to leave it out.
	 *
	 * @see \CBPSignB2EDocumentConfigureActivity::buildRepresentativeStep() the step that writes it
	 * @see \CBPSignB2EDocumentConfigureActivity::makeRegistrationWriter() the company step writing it
	 *
	 * @var list<string>
	 */
	public const COMPOSITION_WRITERS = [
		self::MEMBERS,
		self::REPRESENTATIVE,
		self::COMPANY,
	];

	private const EXTERNAL_APPLIED_KEY = 'externalApplied';
	private const UNCHANGED_KEY = 'unchanged';

	/**
	 * @param \Closure(Item\Document): Main\Result $action
	 */
	private function __construct(
		public readonly string $name,
		public readonly bool $reversible,
		private readonly \Closure $action,
	)
	{
	}

	/**
	 * @param \Closure(Item\Document): Main\Result $action
	 */
	public static function createReversible(string $name, \Closure $action): self
	{
		return new self($name, true, $action);
	}

	/**
	 * @param \Closure(Item\Document): Main\Result $action
	 */
	public static function createIrreversible(string $name, \Closure $action): self
	{
		return new self($name, false, $action);
	}

	/**
	 * The document comes from the applying side: it is re-read after the plan is built,
	 * so the step writes over the state the document has at this moment.
	 */
	public function apply(Item\Document $document): Main\Result
	{
		return ($this->action)($document);
	}

	/**
	 * Marks a failure that the external service has already accepted: only the local write
	 * failed, so the change stays applied and cannot be rolled back.
	 */
	public static function markExternalApplied(Main\Result $result): Main\Result
	{
		return $result->setData($result->getData() + [self::EXTERNAL_APPLIED_KEY => true]);
	}

	public static function isExternalApplied(Main\Result $result): bool
	{
		return ($result->getData()[self::EXTERNAL_APPLIED_KEY] ?? false) === true;
	}

	/**
	 * Marks a step that succeeded with nothing to change: the document already carried what the
	 * step was going to write, so the step has moved nothing and there is nothing to roll back.
	 */
	public static function markUnchanged(Main\Result $result): Main\Result
	{
		return $result->setData($result->getData() + [self::UNCHANGED_KEY => true]);
	}

	public static function isUnchanged(Main\Result $result): bool
	{
		return ($result->getData()[self::UNCHANGED_KEY] ?? false) === true;
	}
}
