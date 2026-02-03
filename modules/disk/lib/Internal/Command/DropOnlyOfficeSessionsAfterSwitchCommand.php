<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command;

use Bitrix\Disk\Internal\Service\Logger\ExceptionToContextConverter;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The command is needed to reset all (not just active)
 * onlyoffice sessions after switching server types.
 *
 * Drop on the remote server, then delete it from the storage.
 * Also delete document restriction log.
 */
class DropOnlyOfficeSessionsAfterSwitchCommand extends AbstractCommand
{
	/**
	 * @param DateTime $switchedAt
	 * @param LoggerInterface|null $logger
	 */
	public function __construct(
		public DateTime $switchedAt,
		public readonly ?LoggerInterface $logger,
	)
	{
	}

	/**
	 * @return Result
	 * @throws Throwable
	 */
	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$this->logger?->debug('drop session start');

			$errorCollection = (new DropOnlyOfficeSessionsAfterSwitchCommandHandler())($this);

			if ($errorCollection instanceof ErrorCollection)
			{
				return $result->addErrors($errorCollection->toArray());
			}

			$this->logger?->debug('drop session end');

			return $result;
		}
		catch (Throwable $exception)
		{
			$this->logger?->error(
				message: 'exception while drop onlyoffice sessions after switch',
				context: ExceptionToContextConverter::convert($exception),
			);

			return $result->addError(new Error(
				message: $exception->getMessage(),
				code: $exception->getCode(),
				customData: [
					'isInternal' => true,
					'exception' => $exception,
				],
			));
		}
	}
}