<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Exception\CheckList;

use Bitrix\Main\Error;
use Bitrix\Tasks\CheckList\Exception\CheckListException;
use Bitrix\Tasks\Util\Error\Collection;

class CheckListMergeException extends CheckListException
{
	/** @var Error[] */
	private readonly array $errors;

	public function __construct(Collection $errors)
	{
		$this->errors = array_map(
			static fn ($error): Error => new Error($error->getMessage(), $error->getCode()),
			$errors->toArray(),
		);
		$error = $this->errors[0] ?? null;

		parent::__construct($error?->getMessage() ?? '');
	}

	/** @return Error[] */
	public function getErrors(): array
	{
		return $this->errors;
	}
}
