<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\LimitEncounter;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

class SendToDomainStoreCommand extends AbstractCommand
{
	private SendToDomainStoreCommandHandler $handler;

	public function __construct(
		public readonly string $domain,
		public readonly string $action,
	)
	{
		$this->handler = ServiceLocator::getInstance()->get(SendToDomainStoreCommandHandler::class);
	}

	protected function execute(): Result
	{
		try
		{
			return ($this->handler)($this);
		}
		catch (Throwable $e)
		{
			$result = new Result();
			$result->addError(new Error($e->getMessage(), $e->getCode()));

			return $result;
		}
	}
}
