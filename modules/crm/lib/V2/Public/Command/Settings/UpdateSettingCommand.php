<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Settings;

use Bitrix\Crm\V2\Internal\Service\Settings\Handler\UpdateSettingCommandHandler;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;

final class UpdateSettingCommand extends AbstractCommand
{
	public function __construct(
		private readonly string $code,
		private readonly mixed $value,
		private readonly int $userId,
	)
	{
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	protected function execute(): Result
	{
		/** @var UpdateSettingCommandHandler $handler */
		$handler = ServiceLocator::getInstance()->get(UpdateSettingCommandHandler::class);

		return $handler->handle($this);
	}
}
