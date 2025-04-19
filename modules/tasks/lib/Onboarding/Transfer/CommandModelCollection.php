<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Transfer;

use ArrayIterator;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use IteratorAggregate;

/** @method CommandModel[] getIterator() */
final class CommandModelCollection implements IteratorAggregate
{
	/** @var CommandModel[]  */
	// #[Validatable]
	// todo: add #[Validatable(iterator: true)] when it will be available
	private array $commandModels;

	public function __construct(CommandModel ...$commandModels)
	{
		$this->commandModels = $commandModels;
	}

	public function isEmpty(): bool
	{
		return empty($this->commandModels);
	}

	public function add(CommandModel $commandModel): void
	{
		$this->commandModels[] = $commandModel;
	}

	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->commandModels);
	}
}