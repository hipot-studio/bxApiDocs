<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command;

use Bitrix\Disk\Internal\Enum\ServersTypesEnum;
use Bitrix\Disk\Internal\Service\Logger\ExceptionToContextConverter;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Command is needed to switch onlyoffice servers type
 * using the bitrix options mechanism.
 */
class SwitchOnlyOfficeServersTypeCommand extends AbstractCommand
{
	/**
	 * @param ServersTypesEnum $newServersType
	 * @param LoggerInterface|null $logger
	 */
	public function __construct(
		public readonly ServersTypesEnum $newServersType,
		public readonly ?LoggerInterface $logger,
	)
	{
	}

	/**
	 * @return Result
	 */
	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$this->logger?->debug('switch start');
			(new SwitchOnlyOfficeServersTypeCommandHandler())($this);
			$this->logger?->debug('switch end');

			return $result;
		}
		catch (Throwable $exception)
		{
			$this->logger?->error(
				message: 'exception while switch onlyoffice servers type',
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