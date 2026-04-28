<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Disk\Internal\Service\CustomServerConfigsMapper;
use Bitrix\Disk\Public\Command\CustomServer\UnregisterCustomServerCommand;
use Bitrix\Disk\Public\Provider\CustomServerAvailabilityProvider;
use Bitrix\Disk\Public\Provider\CustomServerProvider;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Throwable;

readonly class ChangeDefaultViewerServiceCommandHandler
{
	/**
	 * @param CustomServerConfigsMapper $customServerConfigsMapper
	 * @param CustomServerProvider $customServerProvider
	 * @param CustomServerAvailabilityProvider $customServerAvailabilityProvider
	 */
	public function __construct(
		protected CustomServerConfigsMapper $customServerConfigsMapper,
		protected CustomServerProvider $customServerProvider,
		protected CustomServerAvailabilityProvider $customServerAvailabilityProvider,
	)
	{
	}

	/**
	 * @param ChangeDefaultViewerServiceCommand $command
	 * @return void
	 * @throws Throwable
	 * @throws SqlQueryException
	 * @throws NotImplementedException
	 */
	public function __invoke(ChangeDefaultViewerServiceCommand $command): void
	{
		$transactionCallbacks = [];
		$config = $this->getNormalCodeConfigByCustomHandlerCode($command->code);
		$newCode = $command->code;
		$shouldUnregisterCloudClient = false;

		if (is_array($config))
		{
			$newCode = $config['normalCode'];

			$transactionCallbacks[] = function () use ($config) {
				Configuration::setDefaultViewerCustomConfigType($config['customConfigType']);
			};

			if ($newCode === OnlyOfficeHandler::getCode())
			{
				$shouldUnregisterCloudClient = true;

				$transactionCallbacks[] = function () {
					Option::set('disk', 'documents_enabled', 'Y');
				};
			}
		}
		else
		{
			$transactionCallbacks[] = function () {
				(new UnregisterCustomServerCommand())->run();
			};
		}

		if ($shouldUnregisterCloudClient)
		{
			(new UnregisterCloudClientCommand())->run();
		}

		$connection = Application::getConnection();

		$connection->startTransaction();

		try
		{
			Configuration::setDefaultViewerService($newCode);

			foreach ($transactionCallbacks as $transactionCallback)
			{
				$transactionCallback();
			}

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}
	}

	/**
	 * @param string $code
	 * @return array|null
	 * @throws NotImplementedException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	protected function getNormalCodeConfigByCustomHandlerCode(string $code): ?array
	{
		$config = $this->customServerConfigsMapper->getForCustomCodes()[$code] ?? null;
		$customConfigType = $config['customConfigType'] ?? null;

		if (!$customConfigType instanceof CustomServerTypes)
		{
			return null;
		}

		$customServer = $this->customServerProvider->getFirstByType($customConfigType);

		if (!$customServer instanceof CustomServerInterface)
		{
			return null;
		}

		if (!$this->customServerAvailabilityProvider->isAvailableCustomServerForUse($customServer))
		{
			return null;
		}

		return $config;
	}
}
