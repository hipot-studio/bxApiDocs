<?php

namespace Bitrix\Sign\Rest\B2e;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Rest\AccessException;
use Bitrix\Rest\AuthTypeException;
use Bitrix\Rest\Exceptions\ObjectNotFoundException;
use Bitrix\Rest\Oauth\Auth as OauthAuth;
use Bitrix\Rest\RestException;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Operation\GetRegisteredCompanies;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\ProviderCode;
use Bitrix\Sign\Item\Integration\Crm\MyCompany;
use Bitrix\Sign\Item\Integration\Crm\MyCompanyCollection;
use Bitrix\Sign\Service\Integration\Crm\MyCompanyService;
use Bitrix\Main;
use CRestServer;
use CRestUtil;
use IRestService;

Loader::includeModule('rest');

final class CompanyProvider extends IRestService
{
	public const LIMIT = 100;
	public const LIMIT_MAX = 1000;

	public static function onRestServiceBuildDescription(): array
	{
		return [
			'sign.b2e' => [
				'sign.b2e.company.provider.list' => ['callback' => [self::class, 'list'], 'options' => []],
			],
		];
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @throws AccessException
	 * @throws RestException
	 */
	public static function list(array $query, $start, CRestServer $restServer): array
	{
		self::checkAuth($restServer);
		self::checkAccess(['sign.b2e', 'crm'], $restServer);
		self::checkAccessToActions([
			ActionDictionary::ACTION_B2E_DOCUMENT_ADD,
		]);

		$companyUuid = (string)($query['companyUuid'] ?? '');
		$companyCrmId = (int)($query['companyCrmId'] ?? '');

		if (!$companyUuid && !$companyCrmId)
		{
			throw new RestException("Parameter 'companyUuid' or 'companyCrmId' is required");
		}

		if ($companyUuid)
		{
			self::checkAccess(['humanresources.hcmlink'], $restServer);
		}

		$language = $query['language'] ?? 'en';

		$limit = filter_var($query['limit'] ?? self::LIMIT, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
				'max_range' => self::LIMIT_MAX,
				'default' => self::LIMIT,
			],
		]);
		$offset = filter_var($query['offset'] ?? 0, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
				'default' => 0,
			],
		]);

		$result = [];
		try
		{
			$providerVisibilityService = Container::instance()->getProviderVisibilityService();
			$myCompanyService = Container::instance()->getCrmMyCompanyService();
			[$myCompanies, $myCompany] = self::getMyCompany($companyUuid, $companyCrmId, $myCompanyService);
			if (!$myCompany)
			{
				throw new ObjectNotFoundException("Company was not found.");
			}
			if (empty($myCompany->taxId))
			{
				throw new RestException("Unable to access company requisites. Check that the user has CRM permissions and the company has a tax ID configured.");
			}
			$registeredCompaniesOperation = new GetRegisteredCompanies(
				myCompanies: $myCompanies,
				forDocumentInitiatedByType: InitiatedByType::COMPANY,
			);
			$registeredCompaniesOperationResult = $registeredCompaniesOperation->launch();

			if (!$registeredCompaniesOperationResult->isSuccess())
			{
				throw new RestException(
					implode('; ', $registeredCompaniesOperationResult->getErrorMessages()),
				);
			}

			$registeredCompanies = $registeredCompaniesOperation->getResultData();
			$registeredByTaxId = $registeredCompanies[$myCompany->taxId] ?? [];
			$providers = $registeredByTaxId['providers'];

			if (is_array($providers))
			{
				$autoregisterProvidersResult = self::autoregisterProviders($providers, $myCompany);
				if (!$autoregisterProvidersResult->isSuccess())
				{
					throw new RestException('Error while autoregistering providers');
				}

				$index = 0;
				foreach ($providers as $provider)
				{
					if ($providerVisibilityService->isProviderHidden($provider['code']))
					{
						continue;
					}

					if ($index < $offset)
					{
						$index += 1;
						continue;
					}
					if (($index - $offset) >= $limit)
					{
						break;
					}

					$providerCode = $provider['code'] ?? null;
					$providerName = ProviderCode::getProviderName($providerCode, $language);
					if (!empty($provider['name']))
					{
						$providerName .= ' - ' . $provider['name'];
					}

					$date = null;
					if (is_numeric($provider['date'] ?? null))
					{
						$date = Main\Type\DateTime::createFromTimestamp($provider['date']);
					}

					$expires = null;
					if (is_numeric($provider['expires'] ?? null))
					{
						$expires = Main\Type\DateTime::createFromTimestamp($provider['expires']);
						$daysLeft = floor(((int)$provider['expires'] - time()) / 86400);
						// Skip expired providers
						if ($daysLeft < 1)
						{
							continue;
						}
					}

					$result[] = [
						'code' => $provider['code'],
						'uid' => $provider['uid'],
						'name' => $providerName,
						'date' => $date?->format(\DateTimeInterface::ATOM),
						'expires' => $expires?->format(\DateTimeInterface::ATOM),
					];

					$index += 1;
				}
			}
		}
		catch (\Exception $error)
		{
			throw new RestException($error->getMessage(), $error->getCode());
		}

		return $result;
	}

	/**
	 * @return array{0: MyCompanyCollection, 1: ?MyCompany}
	 */
	private static function getMyCompany(
		string $companyUuid,
		int $companyCrmId,
		MyCompanyService $myCompanyService,
	): array
	{
		if ($companyUuid)
		{
			if (!Loader::includeModule('humanresources'))
			{
				return [new MyCompanyCollection(), null];
			}

			$company = Container::instance()->getHcmLinkService()->getCompanyByUniqueId($companyUuid);
			if ($company === null)
			{
				return [new MyCompanyCollection(), null];
			}

			$myCompanies = $myCompanyService->listWithTaxIds(inIds: [$company->myCompanyId], checkRequisitePermissions: true);
			$myCompany = $myCompanies->toArray()[0] ?? null;

			return [$myCompanies, $myCompany];
		}

		$myCompanies = $myCompanyService->listWithTaxIds(inIds: [$companyCrmId], checkRequisitePermissions: true);
		$myCompany = $myCompanies->toArray()[0] ?? null;

		return [$myCompanies, $myCompany];
	}

	/**
	 * Check for virtual providers and autoregister them if it is firs call of method
	 *
	 * @param array $providersList
	 * @param MyCompany $myCompany
	 * @return Main\Result
	 */
	protected static function autoregisterProviders(array &$providersList, MyCompany $myCompany): Main\Result
	{
		foreach ($providersList as &$provider)
		{
			$providerCode = $provider['code'] ?? null;
			if ($provider['virtual'] && $provider['autoRegister'])
			{
				$result = Container::instance()->getApiService()->post('v1/b2e.company.registerByClient', [
					'taxId' => $myCompany->taxId,
					'providerCode' => $providerCode,
					'providerData' => [
						'providerUid' => '',
						'companyName' => $myCompany->name,
					],
				]);

				if (!$result->isSuccess())
				{
					return $result;
				}

				if ($id = $result->getData()['id'] ?? null)
				{
					$provider['uid'] = $id;
				}
			}
		}

		return new Main\Result();
	}

	/**
	 * @throws AccessException
	 */
	private static function checkAuth(CRestServer $restServer): void
	{
		global $USER;

		if (!$USER->isAuthorized())
		{
			throw new AccessException("User authorization required");
		}

		if ($restServer->getAuthType() !== OauthAuth::AUTH_TYPE)
		{
			throw new AuthTypeException("Application context required");
		}

		if (!Storage::instance()->isB2eAvailable())
		{
			throw new AccessException();
		}
	}

	protected static function checkAccess(array $moduleIds, CRestServer $restServer): void
	{
		$scopes = $restServer->getAuthScope();
		foreach ($moduleIds as $moduleId)
		{
			if (!in_array($moduleId, $scopes, true))
			{
				throw new AccessException();
			}
		}
	}

	protected static function checkAccessToActions(array $actions): void
	{
		$accessController = (new AccessController(CurrentUser::get()->getId()));
		foreach ($actions as $action)
		{
			if ($accessController->check($action) !== true)
			{
				throw new AccessException();
			}
		}
	}
}
