<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1;

use Bitrix\Booking\Controller\V1\Request\YandexIntegrationConfigurationRequest;
use Bitrix\Booking\Controller\V1\Response\YandexIntegrationConfiguration;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuCreator;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Service\Yandex\Account;
use Bitrix\Booking\Internals\Service\Yandex\CompanyRepository;
use Bitrix\Booking\Internals\Service\Yandex\Dto\CompanyDataDto;
use Bitrix\Booking\Internals\Service\Yandex\IntegrationService;
use Bitrix\Booking\Internals\Service\Yandex\ResourceSkuRelationsService;
use Bitrix\Booking\Internals\Service\Yandex\StatusService;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;
use Bitrix\Booking\Command\Counter\DropCounterCommand;
use Bitrix\Booking\Internals\Service\CounterDictionary;

class YandexIntegration extends BaseController
{
	private IntegrationService $integrationService;
	private CompanyRepository $companyRepository;
	private StatusService $statusService;
	private ServiceSkuProvider $serviceSkuProvider;
	private ResourceSkuRelationsService $resourceSkuRelationsService;

	protected function init()
	{
		parent::init();

		$this->integrationService = Container::getYandexIntegrationService();
		$this->companyRepository = Container::getYandexCompanyRepository();
		$this->statusService = Container::getYandexStatusService();
		$this->serviceSkuProvider = Container::getCatalogServiceSkuProvider();
		$this->resourceSkuRelationsService = Container::getYandexResourceSkuRelationsService();
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new ValidationParameter(
				YandexIntegrationConfigurationRequest::class,
				fn() => YandexIntegrationConfigurationRequest::mapFromArray(
					$this->request->getJsonList()->get('configuration'),
				),
			),
		];
	}

	public function getConfigurationAction(): YandexIntegrationConfiguration|null
	{
		return $this->getConfiguration();
	}

	public function saveConfigurationAction(
		YandexIntegrationConfigurationRequest $configuration
	): YandexIntegrationConfiguration|null
	{
		$result = $this->saveConfiguration($configuration);
		if (!$result)
		{
			return null;
		}

		return $this->getConfiguration();
	}

	public function deactivateAction(Account $account): YandexIntegrationConfiguration|null
	{
		try
		{
			$this->integrationService->deactivate();

			return $this->getConfiguration();
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function dropCounterAction(): void
	{
		(new DropCounterCommand(
			entityId: 0,
			type: CounterDictionary::BookingNewYandexMaps,
			userId: (int)CurrentUser::get()->getId(),
		))->run();
	}

	private function getConfiguration(): YandexIntegrationConfiguration|null
	{
		try
		{
			$company = $this->companyRepository->getDefaultCompany();
			$userId = (int)CurrentUser::get()->getId();

			return new YandexIntegrationConfiguration(
				status: $this->statusService->getStatus(),
				catalogPermissions: [
					'read' => $this->serviceSkuProvider->checkCatalogReadAccess($userId),
				],
				isResourceSkuRelationsSaved: $this->resourceSkuRelationsService->isSaved(),
				resources: $this->resourceSkuRelationsService->get(),
				// TODO: remove after frontend start using MainPage.get.catalogSkuEntityOptions
				catalogSkuEntityOptions: (new ServiceSkuCreator())
					->getEntitySelectorEntityOptions($userId),
				settings: $this->integrationService->getSettings(),
				cabinetLink: $company?->getCabinetLink(),
				timezone: $company?->getTimezone(),
			);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	private function saveConfiguration(YandexIntegrationConfigurationRequest $configuration): bool
	{
		try
		{
			$this->integrationService->saveConfiguration(
				new CompanyDataDto(
					permalink: $configuration->cabinetId,
					timezone: $configuration->timezone,
					cabinetLink: $configuration->cabinetLink,
				),
				$configuration->resources,
			);

			return true;
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return false;
		}
	}
}
