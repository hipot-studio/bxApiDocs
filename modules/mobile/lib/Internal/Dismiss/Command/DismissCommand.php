<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Dismiss\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Dismiss\DismissCode;

/**
 * Closes an element for a user. The answer carries the whole list, because that answer is what the
 * client stores: the server is the authority on what is closed, not the device.
 */
final class DismissCommand extends AbstractCommand
{
	public const ERROR_CODE_INVALID = 'DISMISS_CODE_INVALID';
	public const ERROR_CODE_FAILED = 'DISMISS_FAILED';

	public function __construct(
		public readonly int $userId,
		public readonly string $code,
		private readonly DismissCommandHandler $handler,
	)
	{
	}

	/**
	 * The rule on the code is a gate and not a validate(): a failed validate() of the base throws,
	 * while the client of the action has to get the refusal back as a Result with a stable code.
	 * A command that does not pass the gate is never executed.
	 */
	protected function beforeRun(): ?Result
	{
		if (DismissCode::tryFrom($this->code) !== null)
		{
			return null;
		}

		return (new Result())->addError(new Error('Dismiss code is invalid.', self::ERROR_CODE_INVALID));
	}

	protected function execute(): Result
	{
		return ($this->handler)($this);
	}
}
