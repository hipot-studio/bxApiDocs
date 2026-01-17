<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Collection\CompanyCollection;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\License\UrlProvider;
use Bitrix\Main\Result;
use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Main\Web\Json;

class ApiClient extends BaseSender
{
	private const CUSTOM_SERVICE_URL_OPTION = 'yandex_service_url';

	public function __construct(private readonly AvailabilityService $availabilityService)
	{
		parent::__construct();
	}

	public function saveCompaniesFeed(CompanyCollection $companyCollection): Result
	{
		if (!$this->availabilityService->isAvailable())
		{
			return $this->getServiceNotAvailableResult();
		}

		$validateFeedResult = $this->validateCompanyFeed($companyCollection);
		if (!$validateFeedResult->isSuccess())
		{
			return $validateFeedResult;
		}

		return $this->performRequest(
			'bookingservice.api_v1.Yandex.Portal.CompanyFeed.saveCompanies',
			[
				'companies' => $this->getRawCompanyFeed($companyCollection),
			]
		);
	}

	public function register(CompanyCollection $companyCollection): Result
	{
		if (!$this->availabilityService->isAvailable())
		{
			return $this->getServiceNotAvailableResult();
		}

		$validateFeedResult = $this->validateCompanyFeed($companyCollection);
		if (!$validateFeedResult->isSuccess())
		{
			return $validateFeedResult;
		}

		return $this->performRequest(
			'bookingservice.api_v1.Yandex.Portal.Account.register',
			[
				'companies' => $this->getRawCompanyFeed($companyCollection),
			]
		);
	}

	public function unregister(): Result
	{
		if (!$this->availabilityService->isAvailable())
		{
			return $this->getServiceNotAvailableResult();
		}

		return $this->performRequest('bookingservice.api_v1.Yandex.Portal.Account.unregister');
	}

	public function setCustomServiceUrl(string $url): void
	{
		Option::set('booking', self::CUSTOM_SERVICE_URL_OPTION, $url);
	}

	protected function getServiceUrl(): string
	{
		if (defined('BOOKING_SERVICE_URL'))
		{
			return BOOKING_SERVICE_URL;
		}

		$customServiceUrl = Option::get('booking', self::CUSTOM_SERVICE_URL_OPTION);
		if (!empty($customServiceUrl))
		{
			return $customServiceUrl;
		}

		return 'https://bookservice-ru.' . (new UrlProvider())->getTechDomain() . '/';
	}

	protected function getClientServerName(): string
	{
		$publicUrl = Configuration::getInstance()->get('booking')['public_url'] ?? '';
		if (!empty($publicUrl))
		{
			return $publicUrl;
		}

		return parent::getClientServerName();
	}

	private function getServiceNotAvailableResult(): Result
	{
		return (new Result())->addError(new Error('Service is not available'));
	}

	private function validateCompanyFeed(CompanyCollection $companyCollection): Result
	{
		$result = new Result();

		$validateResult = $companyCollection->validate();
		if (!$validateResult->isSuccess())
		{
			$result->addErrors($validateResult->getErrors());

			return $result;
		}

		return $result;
	}

	private function getRawCompanyFeed(CompanyCollection $companyCollection): string
	{
		return Json::encode($companyCollection->toArray());
	}
}
