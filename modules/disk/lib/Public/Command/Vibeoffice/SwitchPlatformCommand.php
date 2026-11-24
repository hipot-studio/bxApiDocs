<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\Vibeoffice;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationResult;

final class SwitchPlatformCommand extends AbstractCommand
{
	public function __construct(
		public readonly string $server,
		public readonly string $keyId,
		#[\SensitiveParameter] public readonly string $apiSecret,
		#[\SensitiveParameter] public readonly string $webhookSecret,
	)
	{
	}

	protected function validate(): ValidationResult
	{
		return new ValidationResult();
	}

	protected function execute(): Result
	{
		return ServiceLocator::getInstance()->get(SwitchPlatformCommandHandler::class)($this);
	}
}
