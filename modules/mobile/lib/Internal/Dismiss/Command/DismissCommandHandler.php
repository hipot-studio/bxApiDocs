<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Dismiss\Command;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Dismiss\DismissCode;
use Bitrix\Mobile\Internal\Dismiss\Service\DismissService;
use Bitrix\Mobile\Internal\Dismiss\Storage\DismissStorage;
use Psr\Log\LoggerInterface;

final class DismissCommandHandler
{
	public function __construct(
		private readonly DismissStorage $storage,
		private readonly LoggerInterface $logger,
	)
	{
	}

	/**
	 * The error of the Result reaches the client as is, so the cause of a failure goes to the log only.
	 *
	 * @return Result with the whole state after the write, array{codes: string[], revision: int}, as data
	 */
	public function __invoke(DismissCommand $command): Result
	{
		$result = new Result();

		try
		{
			$code = DismissCode::tryFrom($command->code)
				?? throw new ArgumentException('Dismiss code is invalid.', 'code');

			return $result->setData((new DismissService($this->storage, $command->userId))->dismissWithState($code));
		}
		catch (\Exception $exception)
		{
			$this->logger->error('Could not dismiss the code.', [
				'exception' => $exception,
				'userId' => $command->userId,
				'code' => $command->code,
			]);

			return $result->addError(new Error('Could not dismiss the code.', DismissCommand::ERROR_CODE_FAILED));
		}
	}
}
