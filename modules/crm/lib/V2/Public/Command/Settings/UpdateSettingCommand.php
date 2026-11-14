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
		private readonly string $id,
		private readonly mixed $value,
		private readonly int $userId,
		private readonly ?string $eventId = null,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getEventId(): ?string
	{
		return $this->eventId;
	}

	protected function execute(): Result
	{
		/** @var UpdateSettingCommandHandler $handler */
		$handler = ServiceLocator::getInstance()->get(UpdateSettingCommandHandler::class);

		return $handler->handle($this);
	}
}
