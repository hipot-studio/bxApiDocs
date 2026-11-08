<?php

namespace Bitrix\Sign\Item\Document\Configure;

use Bitrix\Main;
use Bitrix\Sign\Contract;

/**
 * What has to change in the document, or the reasons why it cannot change. Building the plan
 * writes nothing: the steps are applied afterwards, in the order the plan returns them.
 */
final class Plan implements Contract\Item
{
	/**
	 * The representative goes first, so that the company member gets its communications when
	 * the member composition is applied; the only irreversible step goes last.
	 */
	private const APPLY_ORDER = [
		Step::REPRESENTATIVE,
		Step::COMPANY,
		Step::PROVIDER,
		Step::MEMBERS,
		Step::DOCUMENT_CODE,
		Step::EXTERNAL_ID,
		Step::DATE_SIGN_UNTIL,
	];

	/**
	 * @param Step[] $steps
	 */
	private function __construct(
		private readonly array $steps,
		private readonly Main\ErrorCollection $errors,
	)
	{
	}

	public static function createWithSteps(Step ...$steps): self
	{
		return new self(self::sortByApplyOrder($steps), new Main\ErrorCollection());
	}

	public static function createWithErrors(Main\ErrorCollection $errors): self
	{
		return new self([], $errors);
	}

	public function hasErrors(): bool
	{
		return !$this->errors->isEmpty();
	}

	public function getErrors(): Main\ErrorCollection
	{
		return $this->errors;
	}

	/**
	 * An empty plan is a legitimate outcome: there is nothing to apply.
	 */
	public function isEmpty(): bool
	{
		return $this->steps === [];
	}

	/**
	 * @return Step[]
	 */
	public function getSteps(): array
	{
		return $this->steps;
	}

	/**
	 * Whether the plan can move the member composition. The applied composition mirrors its company
	 * member onto the smart process card, so only such a plan makes the card worth capturing.
	 *
	 * @see Step::COMPOSITION_WRITERS the steps the composition is written by
	 */
	public function movesMemberComposition(): bool
	{
		foreach (Step::COMPOSITION_WRITERS as $stepName)
		{
			if ($this->hasStep($stepName))
			{
				return true;
			}
		}

		return false;
	}

	private function hasStep(string $name): bool
	{
		foreach ($this->steps as $step)
		{
			if ($step->name === $name)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param Step[] $steps
	 *
	 * @return Step[]
	 */
	private static function sortByApplyOrder(array $steps): array
	{
		$positions = array_flip(self::APPLY_ORDER);
		$steps = array_values($steps);
		usort(
			$steps,
			static fn(Step $first, Step $second): int =>
				($positions[$first->name] ?? PHP_INT_MAX) <=> ($positions[$second->name] ?? PHP_INT_MAX)
			,
		);

		return $steps;
	}
}
