<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Exception\YandexIntegration\YandexIntegrationAccountRegistrationException;
use Bitrix\Booking\Internals\Exception\YandexIntegration\YandexIntegrationDefaultCompanyNotFound;
use Bitrix\Booking\Internals\Service\Integration\IntegrationServiceInterface;
use Bitrix\Booking\Internals\Service\Integration\IntegrationStatusEnum;
use Bitrix\Booking\Internals\Service\Yandex\Dto\CompanyDataDto;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Validation\ValidationException;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Validator\Company;

class IntegrationService implements IntegrationServiceInterface
{
	public function __construct(
		private readonly ResourceSkuRelationsService $resourceSkuRelationsService,
		private readonly CompanyRepository $companyRepository,
		private readonly Account $account,
		private readonly CompanyFeedProvider $companyFeedProvider,
		private readonly StatusService $statusService,
		private readonly AvailabilityService $availabilityService,
		private ValidationService|null $validationService = null,
	)
	{
		$this->validationService = $validationService ?? ServiceLocator::getInstance()->get('main.validation.service');
	}

	/**
	 * @throws ValidationException
	 * @throws YandexIntegrationAccountRegistrationException
	 * @throws YandexIntegrationDefaultCompanyNotFound
	 * @throws Exception
	 */
	public function saveConfiguration(
		CompanyDataDto $companyDataDto,
		ResourceCollection $resourceCollection,
	): void
	{
		$this->saveCompanyData($companyDataDto);
		$this->resourceSkuRelationsService->save($resourceCollection);
		$this->registerIfNeeded();
	}

	public function deactivate(): void
	{
		$unregisterResult = $this->account->unregister();
		if (!$unregisterResult->isSuccess())
		{
			throw new YandexIntegrationAccountRegistrationException();
		}

		$this->resourceSkuRelationsService->reset();
		$this->resetCompanyData();
	}

	public function getName(): string
	{
		return 'yandex';
	}

	public function getStatus(): IntegrationStatusEnum|null
	{
		return $this->statusService->getStatus();
	}

	public function isAvailable(): bool
	{
		return $this->availabilityService->isAvailable();
	}

	public function getSettings(): array
	{
		$region = Application::getInstance()->getLicense()->getRegion() ?? 'en';

		$domain = match ($region) {
			'ru' => 'ru',
			'by' => 'by',
			'kz' => 'kz',
			'uz' => 'uz',
			'tr' => 'com.tr',
			default => 'com',
		};

		return [
			'businessLink' => 'https://yandex.' . $domain . '/sprav/companies',
			'cabinetLinkPlaceholder' => 'yandex.' . $domain . '/sprav/0000000000/p/edit/',
		];
	}

	private function saveCompanyData(CompanyDataDto $companyDataDto): void
	{
		$validationResult = $this->validationService->validate($companyDataDto);
		if (!$validationResult->isSuccess())
		{
			throw new ValidationException($validationResult->getErrors());
		}

		$defaultCompany = $this->companyRepository->getDefaultCompany();
		if (!$defaultCompany)
		{
			throw new YandexIntegrationDefaultCompanyNotFound();
		}

		$company = $defaultCompany
			->setPermalink($companyDataDto->permalink)
			->setTimezone($companyDataDto->timezone)
			->setCabinetLink($companyDataDto->cabinetLink)
		;
		$this->companyRepository->save($company);
	}

	private function resetCompanyData(): void
	{
		$defaultCompany = $this->companyRepository->getDefaultCompany();
		if (!$defaultCompany)
		{
			throw new YandexIntegrationDefaultCompanyNotFound();
		}

		$company = $defaultCompany
			->setPermalink('')
			->setTimezone(null)
			->setCabinetLink('')
		;
		$this->companyRepository->save($company);
	}

	private function registerIfNeeded(): void
	{
		if ($this->account->isRegistered())
		{
			CompanyFeedSenderAgent::rescheduleForNow();

			return;
		}

		$registerResult = $this->account->register(
			$this->companyFeedProvider->getCompanies()
		);
		if (!$registerResult->isSuccess())
		{
			$errors = $registerResult->getErrors();
			foreach ($errors as $error)
			{
				if ($error->getCode() === Company::ERROR_CODE_EMPTY_SERVICES)
				{
					throw (new YandexIntegrationAccountRegistrationException(
						Loc::getMessage('BOOKING_YANDEX_INTEGRATION_SERVICE_EMPTY_SKUS_ERROR')
					))->setIsPublic(true);
				}
			}

			throw new YandexIntegrationAccountRegistrationException();
		}
	}
}
