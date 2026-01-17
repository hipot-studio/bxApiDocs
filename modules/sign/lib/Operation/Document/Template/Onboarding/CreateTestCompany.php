<?php

namespace Bitrix\Sign\Operation\Document\Template\Onboarding;

use Bitrix\Crm\Item\Company as CrmCompanyItem;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item\Api\Company\RegisterByClientResponse;
use Bitrix\Sign\Service\B2e\CompanyService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\MyCompanyService;
use Bitrix\Sign\Type\ProviderCode;

class CreateTestCompany implements Operation
{
	private const TAX_ID_FOR_DEMO_SIGNING = '0000000000';
	private const PROVIDER_CODE_FOR_DEMO_COMPANY = ProviderCode::SES_RU_EXPRESS;

	private readonly MyCompanyService $myCompanyService;
	private readonly CompanyService $companyService;

	private ?string $companyUid = null;
	private ?int $companyEntityId = null;
	private readonly int $currentUserId;

	public function __construct(int $currentUserId)
	{
		$container = Container::instance();
		$this->myCompanyService = $container->getCrmMyCompanyService();
		$this->companyService = $container->getCompanyService();
		$this->currentUserId = $currentUserId;
	}

	public function getCompanyUid(): ?string
	{
		return $this->companyUid;
	}

	public function getCompanyEntityId(): ?int
	{
		return $this->companyEntityId;
	}

	public function launch(): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			$result->addError(new \Bitrix\Main\Error('CRM module is not installed'));
			return $result;
		}

		// create "my company"
		$companyId = $this->createMyCompany();
		if (!$companyId)
		{
			$result->addError(new \Bitrix\Main\Error('Failed to create company'));
			return $result;
		}

		$this->companyEntityId = $companyId;

		// add test requisites
		$requisiteResult = $this->addRequisites($companyId);
		if (!$requisiteResult->isSuccess())
		{
			$result->addErrors($requisiteResult->getErrors());
			return $result;
		}

		// register company
		$registerResult = $this->registerCompanyInApi($companyId);
		if (!$registerResult->isSuccess())
		{
			$result->addErrors($registerResult->getErrors());
			return $result;
		}

		return $result;
	}

	private function createMyCompany(): ?int
	{
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Company);

		if (!$factory)
		{
			return null;
		}

		$item = $factory->createItem()
			->setTitle(self::getLocalizedRequisitesPhrase('SIGN_OPERATION_CREATE_EXAMPLE_COMPANY_TITLE'))
			->set(CrmCompanyItem::FIELD_NAME_IS_MY_COMPANY, 'Y')
			->set(CrmCompanyItem::FIELD_NAME_INDUSTRY, 'OTHER')
			->setAssignedById($this->currentUserId)
			->setCreatedBy($this->currentUserId)
			->setOpened(true)
			->setComments(self::getLocalizedRequisitesPhrase('SIGN_OPERATION_CREATE_EXAMPLE_COMPANY_COMMENTS'))
			->setTypeId('OTHER')
		;

		$addResult = $factory->getAddOperation($item)->launch();

		return $addResult->isSuccess() ? $item->getId() : null;
	}

	private function addRequisites(int $companyId): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		$entityRequisite = \Bitrix\Crm\EntityRequisite::getSingleInstance();

		// Get default preset for company
		$presetId = $entityRequisite->getDefaultPresetId(\CCrmOwnerType::Company);
		if (!$presetId)
		{
			$result->addError(new \Bitrix\Main\Error('Default preset not found'));
			return $result;
		}

		$requisiteFields = [
			'ENTITY_TYPE_ID' => \CCrmOwnerType::Company,
			'ENTITY_ID' => $companyId,
			'PRESET_ID' => $presetId,
			'NAME' => self::getLocalizedRequisitesPhrase('SIGN_OPERATION_CREATE_EXAMPLE_COMPANY_REQUISITE_NAME'),
			'ACTIVE' => 'Y',
			'CREATED_BY_ID' => $this->currentUserId,
			'RQ_COMPANY_NAME' => self::getLocalizedRequisitesPhrase('SIGN_OPERATION_CREATE_EXAMPLE_COMPANY_NAME'),
			'RQ_COMPANY_FULL_NAME' => self::getLocalizedRequisitesPhrase('SIGN_OPERATION_CREATE_EXAMPLE_COMPANY_FULL_NAME'),
			'RQ_INN' => self::TAX_ID_FOR_DEMO_SIGNING,
		];

		$addResult = $entityRequisite->add($requisiteFields, [
			'DISABLE_REQUIRED_USER_FIELD_CHECK' => true,
		]);

		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());
		}

		return $result;
	}

	private function registerCompanyInApi(int $companyId): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		$companies = $this->myCompanyService->listWithTaxIds(inIds: [$companyId], checkRequisitePermissions: false);

		$myCompany = null;
		foreach ($companies as $company)
		{
			$myCompany = $company;
			break;
		}

		if (!$myCompany)
		{
			$result->addError(new \Bitrix\Main\Error('Failed to get company'));
			return $result;
		}

		if (!$myCompany->taxId)
		{
			$result->addError(new \Bitrix\Main\Error('Failed to get company tax ID from requisites'));
			return $result;
		}

		$registerResult = $this->registerCompany($myCompany->taxId, $myCompany->name);

		if (!$registerResult->isSuccess())
		{
			$result->addErrors($registerResult->getErrors());
			return $result;
		}

		$this->companyUid = $registerResult->id;

		if (!$this->companyUid)
		{
			$result->addError(new \Bitrix\Main\Error('Failed to get company UID'));
		}

		return $result;
	}

	private function registerCompany(string $taxId, string $companyName): RegisterByClientResponse
	{
		return $this->companyService->register(
			taxId: $taxId,
			providerCode: self::PROVIDER_CODE_FOR_DEMO_COMPANY,
			companyName: $companyName,
		);
	}

	private static function getLocalizedRequisitesPhrase(string $code): ?string
	{
		$isRu = self::PROVIDER_CODE_FOR_DEMO_COMPANY === ProviderCode::SES_RU_EXPRESS;
		return Loc::getMessage($code, null, $isRu ? 'ru' : null);
	}
}
