<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Handler;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;
use Bitrix\Crm\V2\Internal\Service\Settings\Mutation\TransportValueMapper;
use Bitrix\Crm\V2\Internal\Service\Settings\Presentation\SettingsPresentationProvider;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingRegistryInterface;
use Bitrix\Crm\V2\Public\Command\Settings\UpdateSettingCommand;
use Bitrix\Main\Result;

/** @internal */
final class UpdateSettingCommandHandler
{
	public function __construct(
		private readonly SettingRegistryInterface $registry,
		private readonly TransportValueMapper $transportValueMapper,
		private readonly SettingsPresentationProvider $settingsPresentationProvider,
	)
	{
	}

	public function handle(UpdateSettingCommand $command): Result
	{
		$permissions = Container::getInstance()->getUserPermissions($command->getUserId());
		$code = $command->getCode();

		try
		{
			$registration = $this->registry->getRegistrationByCode($code);
		}
		catch (SettingNotFoundException)
		{
			return (new Result())->addError(ErrorCode::getNotFoundError());
		}

		$setting = $this->registry->getByCode($code);
		if (!$this->registry->canWrite($registration, $permissions))
		{
			return (new Result())->addError(ErrorCode::getAccessDeniedError());
		}

		$mappingResult = $this->transportValueMapper->map(
			$setting->getSchema(),
			$code,
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

		return $this->settingsPresentationProvider->get($permissions);
	}
}
