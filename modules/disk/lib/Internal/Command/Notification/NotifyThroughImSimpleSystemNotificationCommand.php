<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\Notification;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Closure;
use Throwable;

class NotifyThroughImSimpleSystemNotificationCommand extends AbstractCommand
{
	private NotifyThroughImSimpleSystemNotificationCommandHandler $handler;

	/**
	 * @param Closure $title
	 * @param Closure $message
	 * @param int[] $recipients user IDs
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function __construct(
		public readonly Closure $title,
		public readonly Closure $message,
		#[NotEmpty]
		#[ElementsType(Type::Integer)]
		public readonly array $recipients,
	)
	{
		$this->handler = ServiceLocator::getInstance()->get(NotifyThroughImSimpleSystemNotificationCommandHandler::class);
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
