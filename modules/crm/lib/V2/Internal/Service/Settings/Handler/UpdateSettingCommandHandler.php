<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Handler;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;
use Bitrix\Crm\V2\Internal\Integration\Pull\SettingsHubPull;
use Bitrix\Crm\V2\Internal\Service\Settings\Mutation\TransportValueMapper;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingRegistryInterface;
use Bitrix\Crm\V2\Public\Command\Settings\UpdateSettingCommand;
use Bitrix\Main\Result;
use Throwable;

/** @internal */
final class UpdateSettingCommandHandler
{
	public function __construct(
		private readonly SettingRegistryInterface $registry,
		private readonly TransportValueMapper $transportValueMapper,
		private readonly SettingsHubPull $settingsHubPull,
	)
	{
	}

	public function handle(UpdateSettingCommand $command): Result
	{
		$permissions = Container::getInstance()->getUserPermissions($command->getUserId());
		$id = $command->getId();

		try
		{
			$registration = $this->registry->getRegistrationById($id);
		}
		catch (SettingNotFoundException)
		{
			return (new Result())->addError(ErrorCode::getNotFoundError());
		}

		if (!$this->registry->canWrite($registration, $permissions))
		{
			return (new Result())->addError(ErrorCode::getAccessDeniedError());
		}
		$setting = $this->registry->getById($id);

		$mappingResult = $this->transportValueMapper->map(
			$setting->getSchema(),
			$id,
			$command->getValue(),
		);
		if (!$mappingResult->isSuccess())
		{
			return (new Result())->addErrors($mappingResult->getErrors());
		}

		$settingResult = $setting->set($mappingResult->getData()['value']);
		if (!$settingResult->isSuccess())
		{
			return (new Result())->addErrors($settingResult->getErrors());
		}

		try
		{
			$this->settingsHubPull->publish($command->getEventId());
		}
		catch (Throwable)
		{
			// Pull transport must not undo a completed settings write.
		}

		return new Result();
	}
}
