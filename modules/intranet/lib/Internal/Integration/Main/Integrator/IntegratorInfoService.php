<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main\Integrator;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\License;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

class IntegratorInfoService
{
	private const DELETE_PARTNER_TYPE = 'delete_partner';
	private const GET_PARTNER_TYPE = 'get_partner';

	public function __construct(
		private readonly License $license,
	)
	{
	}

	public static function createByDefault(): self
	{
		return new self(
			Application::getInstance()->getLicense(),
		);
	}

	public function getIntegratorInfo(): IntegratorInfoDto
	{
		if (!$this->license->getPartnerId())
		{
			return new IntegratorInfoDto();
		}

		$partnerId = Option::get('bitrix24', 'partner_id', '');

		if ($partnerId === '')
		{
			return $this->updateIntegratorInfo();
		}

		return $this->getSavedIntegratorInfo();
	}

	private function getSavedIntegratorInfo(): IntegratorInfoDto
	{
		$partnerId = Option::get('bitrix24', 'partner_id', '');
		if ($partnerId === '')
		{
			return new IntegratorInfoDto();
		}

		return new IntegratorInfoDto(
			id: (int)$partnerId,
			name: Option::get('bitrix24', 'partner_name', ''),
			url: Option::get('bitrix24', 'partner_url', ''),
			phone: Option::get('bitrix24', 'partner_phone', ''),
			ol: Option::get('bitrix24', 'partner_ol', ''),
			canContact: Option::get('bitrix24', 'partner_can_contact', 'N') === 'Y',
			logo: Option::get('bitrix24', 'partner_logo', ''),
			company: Option::get('bitrix24', 'partner_company', ''),
			email: Option::get('bitrix24', 'partner_email', ''),
		);
	}

	public function deletePartner(bool $fromCheckout = false): Result
	{
		$result = new Result();

		$user = CurrentUser::get();
		$hashKey = $this->license->getHashLicenseKey();

		$requestParams = [
			'type' => self::DELETE_PARTNER_TYPE,
			'user_id' => (string)$user->getId(),
			'user_name' => (string)$user->getFullName(),
			'is_admin' => $user->isAdmin() ? 'Y' : 'N',
			'email' => (string)$user->getEmail(),
			'phone' => $user->getPhoneNumber(),
			'key' => $hashKey,
			'hash' => md5($hashKey . 'DELETE_PARTNER'),
			'from_checkout' => $fromCheckout ? 'Y' : 'N',
		];

		$httpClient = $this->createHttpClient();
		$responseResult = $httpClient->post((string)$this->getUtilUri(), $requestParams);

		if (
			$responseResult
			&& in_array($httpClient->getStatus(), [200, 210], true)
			&& is_string($responseResult)
		)
		{
			try
			{
				$responseResult = Json::decode($responseResult);

				if (
					(isset($responseResult['result']) && $responseResult['result'] === 'OK')
					|| (isset($responseResult['message']) && $responseResult['message'] === 'NO_PARTNER')
				)
				{
					$this->clearPartnerOptions();

					return $result;
				}
			}
			catch (ArgumentException)
			{
			}
		}

		$result->addError(new Error('unknown error', 'UNKNOWN_ERROR'));

		return $result;
	}

	public function updateIntegratorInfo(): IntegratorInfoDto
	{
		$integratorInfo = $this->license->getPartnerId()
			? $this->fetchIntegratorInfo()
			: new IntegratorInfoDto()
		;
		if ($integratorInfo === null)
		{
			return $this->getSavedIntegratorInfo();
		}

		$this->saveIntegratorInfo($integratorInfo);

		return $integratorInfo;
	}

	private function getUtilUri(): Uri
	{
		$uri = new Uri($this->license->getDomainStoreLicense());

		return $uri->setPath('b24/util.php');
	}

	private function fetchIntegratorInfo(): ?IntegratorInfoDto
	{
		$httpClient = $this->createHttpClient();
		$hashKey = $this->license->getHashLicenseKey();

		$requestParams = [
			'type' => self::GET_PARTNER_TYPE,
			'key' => $hashKey,
			'hash' => md5($hashKey . 'GET_PARTNER'),
		];
		$responseResult = $httpClient->post((string)$this->getUtilUri(), $requestParams);

		if (!is_string($responseResult) || !in_array($httpClient->getStatus(), [200, 210], true))
		{
			return null;
		}

		try
		{
			$partnerData = Json::decode($responseResult);
		}
		catch (ArgumentException)
		{
			return null;
		}

		if (!is_array($partnerData) || isset($partnerData['error']))
		{
			return null;
		}

		if ($partnerData === [] || ($partnerData['message'] ?? null) === 'NO_PARTNER')
		{
			return new IntegratorInfoDto();
		}

		$partnerId = $partnerData['id'] ?? null;
		if (
			(!is_int($partnerId) && !(is_string($partnerId) && preg_match('/^[0-9]+$/D', $partnerId) === 1))
			|| (int)$partnerId < 0
		)
		{
			return null;
		}

		if ((int)$partnerId === 0)
		{
			return new IntegratorInfoDto();
		}

		foreach (['name', 'url', 'phone', 'ol', 'logo', 'companyName', 'email'] as $field)
		{
			if (isset($partnerData[$field]) && !is_string($partnerData[$field]))
			{
				return null;
			}
		}
		if (isset($partnerData['canContact']) && !is_bool($partnerData['canContact']))
		{
			return null;
		}

		return new IntegratorInfoDto(
			id: (int)$partnerId,
			name: $partnerData['name'] ?? '',
			url: $partnerData['url'] ?? '',
			phone: $partnerData['phone'] ?? '',
			ol: $partnerData['ol'] ?? '',
			canContact: $partnerData['canContact'] ?? false,
			logo: $partnerData['logo'] ?? '',
			company: $partnerData['companyName'] ?? '',
			email: $partnerData['email'] ?? '',
		);
	}

	protected function createHttpClient(): HttpClient
	{
		return new HttpClient([
			'socketTimeout' => 25,
			'streamTimeout' => 25,
		]);
	}

	private function saveIntegratorInfo(IntegratorInfoDto $integratorInfo): void
	{
		Option::set('bitrix24', 'partner_id', (string)$integratorInfo->id);
		Option::set('bitrix24', 'partner_name', $integratorInfo->name);
		Option::set('bitrix24', 'partner_url', $integratorInfo->url);
		Option::set('bitrix24', 'partner_phone', $integratorInfo->phone);
		Option::set('bitrix24', 'partner_ol', $integratorInfo->ol);
		Option::set('bitrix24', 'partner_can_contact', $integratorInfo->canContact ? 'Y' : 'N');
		Option::set('bitrix24', 'partner_logo', $integratorInfo->logo);
		Option::set('bitrix24', 'partner_company', $integratorInfo->company);
		Option::set('bitrix24', 'partner_email', $integratorInfo->email);
	}

	public function clearPartnerOptions(): void
	{
		Option::set('main', '~PARAM_PARTNER_ID', '0');
	}
}
