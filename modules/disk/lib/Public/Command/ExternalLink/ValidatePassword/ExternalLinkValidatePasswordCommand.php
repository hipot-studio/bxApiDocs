<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\ValidatePassword;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

class ExternalLinkValidatePasswordCommand extends AbstractCommand
{
	/**
	 * @param mixed $objectId
	 * @param string|null $password
	 */
	public function __construct(
		public readonly mixed $objectId,
		public readonly ?string $password,
		public readonly mixed $sourceIp,
		public readonly mixed $captchaSid = null,
		public readonly mixed $captchaWord = null,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$error = ServiceLocator::getInstance()->get(ExternalLinkValidatePasswordCommandHandler::class)($this);

			if ($error instanceof Error)
			{
				$result->addError($error);
			}
		}
		catch (Throwable $exception)
		{
			// TODO log?
			$result->addError(new Error(
				message: $exception->getMessage(),
				code: $exception->getCode(),
			));
		}

		return $result;
	}
}
