<?php

namespace Bitrix\Sign\Operation\Rest;

use Bitrix\Main;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Type\DateTime as BitrixDateTime;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Document\Config\DocumentMemberConfig;
use Bitrix\Sign\Item\Integration\Crm\MyCompany;
use Bitrix\Sign\Operation\GetRegisteredCompanies;
use Bitrix\Sign\Repository\RegionDocumentTypeRepository;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\Api\B2e\ProviderCodeService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Item\Api\Rest\SignDocument\CompanyRequestData;
use Bitrix\Sign\Item\Api\Rest\SignDocument\SignDocumentRequest;
use Bitrix\Sign\Item\Api\Rest\SignDocument\SignMemberRequestData;
use Bitrix\Sign\Item;
use Bitrix\HumanResources\Item\HcmLink\Company as HcmLinkCompany;
use Bitrix\Sign\Service\Integration\Crm\MyCompanyService;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkService;
use Bitrix\Sign\Service\UserService;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Member\Role;
use Throwable;
use DateTime;

final class PrepareDocumentSendRequest implements Contract\Operation
{
	private const EXTERNAL_ID_LENGTH_LIMIT = 255;

	public Document $document;
	public Document\Config\DocumentFillConfig $fillConfig;

	/** @var DocumentMemberConfig[] $signers */
	private array $signers = [];
	private ?DocumentMemberConfig $representative = null;
	private ?DocumentMemberConfig $editor = null;
	private ?DocumentMemberConfig $reviewer = null;
	private ?DocumentMemberConfig $responsible = null;
	private ?HcmLinkCompany $hcmlinkCompany;
	private ?MyCompany $myCompany;

	private const ALLOWED_FILE_TYPES = [
		'application/pdf',
	];

	private const ALLOWED_FILE_EXTENSIONS = [
		'pdf',
	];

	public function __construct(
		private readonly SignDocumentRequest $request,
		private ?ProviderCodeService $apiProviderCodeService = null,
		private ?HcmLinkService $hcmLinkService = null,
		private ?UserService $userService = null,
		private ?MyCompanyService $myCompanyService = null,
		private ?RegionDocumentTypeRepository $regionDocumentTypeRepository = null,
	)
	{
		$this->apiProviderCodeService ??= Container::instance()->getApiProviderCodeService();
		$this->userService ??= Container::instance()->getUserService();
		$this->myCompanyService ??= Container::instance()->getCrmMyCompanyService();
		$this->hcmLinkService ??= Container::instance()->getHcmLinkService();
		$this->regionDocumentTypeRepository ??= Container::instance()->getRegionDocumentTypeRepository();
	}

	public function launch(): Main\Result
	{
		$validateResult = $this->validateAndFillRequestData($this->request);
		if (!$validateResult->isSuccess())
		{
			return $validateResult;
		}

		return $this->fillAndPrepareConfig($this->request);
	}

	private function validateAndFillRequestData(SignDocumentRequest $request): Main\Result
	{
		if (empty($request->fields))
		{
			return Result::createByErrorData(message: '[fields] Empty request fields.');
		}

		if (empty($request->fields->getCompanyProviderUid()))
		{
			return Result::createByErrorData(message: '[companyProviderUid] field is required');
		}

		$providerCode = $this->apiProviderCodeService->loadProviderCode($request->fields->getCompanyProviderUid());
		if (!$providerCode)
		{
			return Result::createByErrorData(message: '[companyProviderUid] Could not get provider information.');
		}

		if (!$request->fields->getResponsible())
		{
			return Result::createByErrorData(message: '[responsible] The "responsible" field is required.');
		}

		$result = [];
		/** @var Main\Result[] $result */
		list($result[], $this->hcmlinkCompany, $this->myCompany) = $this->getCompany($request->fields->getCompany());

		foreach ($request->fields->getMembers() as $memberRequestData)
		{
			/** Main\Result $res */
			list ($res, $member) = $this->getUserOrEmployee(
				$memberRequestData,
				$this->hcmlinkCompany,
				'members'
			);
			$result[] = $res;
			if ($member)
			{
				switch ($member->role)
				{
					case Role::SIGNER:
						$this->signers[] = $member;
						break;
					case Role::ASSIGNEE:
						$this->representative = $member;
						break;
					case Role::REVIEWER:
						$this->reviewer = $member;
						break;
					case Role::EDITOR:
						$this->editor = $member;
						break;
					default:
						break;
				}
			}
			else
			{
				$result[] = Result::createByErrorData(message: '[signers] Signing party was not found.');
			}
		}

		list($result[], $this->responsible) = $this->getUserOrEmployee(
			$request->fields->getResponsible(),
			$this->hcmlinkCompany,
			'responsible'
		);

		if (count($this->signers ?? []) == 0)
		{
			return Result::createByErrorData(message: '[members] At least one signing party with role "signer" is required.');
		}

		if (!$this->representative)
		{
			return Result::createByErrorData(message: '[members] At least one signing party with role "assignee" is required.');
		}

		foreach ($result as $res)
		{
			if (!$res->isSuccess())
			{
				return $res;
			}
		}

		if (count($request->fields->getFiles() ?? []) === 0)
		{
			return Result::createByErrorData(message: '[files] At least one file is required to sign the document.');
		}
		if (count($request->fields->getFiles() ?? []) > 1)
		{
			return Result::createByErrorData(message: '[files] Signing multiple files is currently not supported.');
		}

		$file = $request->fields->getFiles()[0];
		if (!in_array($file->getFileType(), self::ALLOWED_FILE_TYPES, true))
		{
			return Result::createByErrorData(message: "[files] The specified file type is not supported.");
		}
		if (!Path::validateFilename($file->getFileName()))
		{
			return Result::createByErrorData(message: "[files] Invalid file name.");
		}

		$fileExtension = Path::getExtension($file->getFileName());
		if (empty($fileExtension))
		{
			return Result::createByErrorData(message: "[files] Invalid file name. File extension is not specified.");
		} elseif (!in_array($fileExtension, self::ALLOWED_FILE_EXTENSIONS, true))
		{
			return Result::createByErrorData(message: "[files] The specified file extension is not supported.");
		}

		$validateRegionTypeResult = $this->validateRegionTypeCode($request->fields->getRegionDocumentType());
		if (!$validateRegionTypeResult->isSuccess())
		{
			return $validateRegionTypeResult;
		}

		$validateProviderResult = $this->validateProvider($request->fields->getCompanyProviderUid());
		if (!$validateProviderResult->isSuccess())
		{
			return $validateProviderResult;
		}

		return new Main\Result();
	}

	/**
	 * @param ?SignMemberRequestData $memberRequestData
	 * @param HcmLinkCompany|null $company
	 * @param string $fieldName
	 * @return array<Main\Result, DocumentMemberConfig|null>
	 */
	private function getUserOrEmployee(?SignMemberRequestData $memberRequestData, ?HcmLinkCompany $company, string $fieldName): array
	{
		if (!$memberRequestData)
		{
			return [new Main\Result(), null];
		}

		$user = null;
		$employee = null;
		if (!in_array($memberRequestData->getRole(), Role::getAll()) && $fieldName == 'members')
		{
			return [Result::createByErrorData(message: "[{$fieldName}] invalid role {$memberRequestData->getRole()}"), null];
		}
		$isEmployeeDataFilled = ($memberRequestData->getEmployeeId() || $memberRequestData->getEmployeeCode());
		if ($isEmployeeDataFilled && !$company)
		{
			return [Result::createByErrorData(message: "[{$fieldName}] Employee ID/code requires an HCM linked company. Use userId."), null];
		}

		if ($company && $isEmployeeDataFilled)
		{
			if ($memberRequestData->getEmployeeCode())
			{
				$employee = $this->hcmLinkService->getEmployeesByUnique($company->id, $memberRequestData->getEmployeeCode());
				if ($employee === null)
				{
					return [Result::createByErrorData(message: "[{$fieldName}] Employee with code {$memberRequestData->getEmployeeCode()} was not found."), null];
				}
			}
			elseif ($memberRequestData->getEmployeeId())
			{
				$employee = $this->hcmLinkService->getEmployeesByIds([$memberRequestData->getEmployeeId()])->getFirst();
				if ($employee === null)
				{
					return [Result::createByErrorData(message: "[{$fieldName}] Employee with ID {$memberRequestData->getEmployeeId()} was not found."), null];
				}
			}
			if ($employee)
			{
				$person = $this->hcmLinkService->getPersonById($employee->personId);
				if ($person && $person->userId)
				{
					$user =$this->userService->getUserById($person->userId);
				}
				else
				{
					return [Result::createByErrorData(message: "[{$fieldName}] Could not find user for specified employee."), null];
				}
			}
		}
		elseif ($memberRequestData->getUserId())
		{
			$userId = (int) $memberRequestData->getUserId();
			$user = $this->userService->getUserById($userId);
			if (!$user)
			{
				return [Result::createByErrorData(message: "[{$fieldName}] Could not find employee for specified User ID"), null];
			}
		}
		else
		{
			return [Result::createByErrorData(message: "[{$fieldName}] Employee ID is required."), null];
		}

		$memberConfig = null;
		if ($user)
		{
			$memberConfig = new DocumentMemberConfig($user->id, $employee?->id, $memberRequestData->getRole());
		}

		return [new Main\Result(), $memberConfig];
	}

	/**
	 * @param CompanyRequestData|null $companyRequestData
	 * @return array<Main\Result, ?HcmLinkCompany, ?MyCompany>
	 */
	private function getCompany(?CompanyRequestData $companyRequestData): array
	{
		if (!$companyRequestData)
		{
			return [Result::createByErrorData(message: '[company] Company data is required.'), null, null];
		}

		if ($companyRequestData->getUuid())
		{
			$company = $this->hcmLinkService->getCompanyByUniqueId($companyRequestData->getUuid());
			if ($company === null)
			{
				return [Result::createByErrorData(message: "[company] Company was not found."), null, null];
			}

			$myCompanies = $this->myCompanyService->listWithTaxIds(inIds: [$company->myCompanyId], checkRequisitePermissions: true);
			$myCompany = $myCompanies->toArray()[0] ?? null;
			if (!$myCompany)
			{
				return [Result::createByErrorData(message: "[company] Could not find company ID {$company->myCompanyId} in the CRM."), null, null];
			}
			if (empty($myCompany->taxId))
			{
				return [Result::createByErrorData(message: "[company] Unable to access company requisites. Check that the user has CRM permissions and the company has a tax ID configured."), null, null];
			}

			return [new Main\Result(), $company, $myCompany];
		}
		elseif ($companyRequestData->getCrmId())
		{
			$crmCompanyId = $companyRequestData->getCrmId();
			$myCompanies = $this->myCompanyService->listWithTaxIds(inIds: [$crmCompanyId], checkRequisitePermissions: true);
			$myCompany = $myCompanies->toArray()[0] ?? null;
			if (!$myCompany)
			{
				return [Result::createByErrorData(message: "[company] Could not find company ID {$crmCompanyId} in the CRM."), null, null];
			}
			if (empty($myCompany->taxId))
			{
				return [Result::createByErrorData(message: "[company] Unable to access company requisites. Check that the user has CRM permissions and the company has a tax ID configured."), null, null];
			}

			return [new Main\Result(), null, $myCompany];
		}
		else
		{
			return [Result::createByErrorData(message: '[company] Company ID is required.'), null, null];
		}
	}

	private function validateRegionTypeCode(?string $regionTypeCode): Main\Result
	{
		$regionCode = Main\Application::getInstance()->getLicense()->getRegion();
		$regionTypes = $this->regionDocumentTypeRepository->listByRegionCode($regionCode)->toArray();
		$regionTypeCodes = array_map(fn ($item) => $item['code'], $regionTypes);
		if (!empty($regionTypeCodes) && !in_array($regionTypeCode, $regionTypeCodes))
		{
			if (empty($regionTypeCode))
			{
				return Result::createByErrorData(message: "[regionDocumentType] is required. Use value '12.999' as a default value.");
			}
			else
			{
				return Result::createByErrorData(message: "[regionDocumentType] is not valid. Use value '12.999' as a default value.");
			}
		}

		return new Main\Result();
	}

	private function validateProvider(string $providerUid): Main\Result
	{
		$registeredCompaniesOperation = new GetRegisteredCompanies(
			myCompanies: new Item\Integration\Crm\MyCompanyCollection($this->myCompany),
			forDocumentInitiatedByType: InitiatedByType::COMPANY,
		);
		$registeredCompaniesOperationResult = $registeredCompaniesOperation->launch();

		if (!$registeredCompaniesOperationResult->isSuccess())
		{
			return $registeredCompaniesOperationResult;
		}

		$registeredCompanies = $registeredCompaniesOperation->getResultData();
		$registeredByTaxId = $registeredCompanies[$this->myCompany->taxId] ?? [];
		$providers = $registeredByTaxId['providers'];

		$provider = null;
		if (is_array($providers))
		{
			foreach ($providers as $providerItem)
			{
				if ($providerItem['uid'] === $providerUid)
				{
					$provider = $providerItem;
					break;
				}
			}
		}

		if (!$provider)
		{
			return Result::createByErrorData(message: "[companyProviderUid] Signing provider is not available.");
		}

		if (is_numeric($provider['expires'] ?? null))
		{
			$daysLeft = floor(((int)$provider['expires'] - time()) / 86400);
			if ($daysLeft < 1)
			{
				return Result::createByErrorData(message: "[companyProviderUid] Signing provider has expired.");
			}
		}

		return new Main\Result();
	}

	/**
	 * @param SignDocumentRequest $request
	 * @return Main\Result
	 */
	private function fillAndPrepareConfig(SignDocumentRequest $request): Main\Result
	{
		$file = $request->fields->getFiles()[0];
		$fileName = Path::replaceInvalidFilename($file->getFileName(), function()
		{
			return '_';
		});

		$sourceFile = new Item\Document\Config\DocumentSourceFile(
			name: $fileName,
			type: $file->getFileType(),
			content: $file->getFileContent(),
		);

		if (empty($request->fields->getExternalSettings()?->getExternalDateCreate()))
		{
			return Result::createByErrorData(message: '[externalSettings.externalDateCreate] field is required');
		}

		if (empty($request->fields->getExternalSettings()?->getExternalId()))
		{
			return Result::createByErrorData(message: '[externalSettings.externalId] field is required');
		}

		try
		{
			$externalDateCreate = !empty($request->fields->getExternalSettings()?->getExternalDateCreate())
				? new BitrixDateTime($request->fields->getExternalSettings()?->getExternalDateCreate(), DateTime::ATOM)
				: null
			;
		}
		catch (Throwable $e)
		{
			return Result::createByErrorMessage(message: '[externalSettings.externalDateCreate] Invalid field value.');
		}

		$externalSettings = new Document\Config\DocumentExternalSettings(
			externalId: $request->fields->getExternalSettings()?->getExternalId(),
			externalDateCreate: $externalDateCreate,
		);

		if (mb_strlen($externalSettings->externalId ?? '') > self::EXTERNAL_ID_LENGTH_LIMIT)
		{
			return Result::createByErrorMessage(message: '[externalSettings.externalId] Maximum length of [255] is exceeded');
		}

		$this->fillConfig = new Item\Document\Config\DocumentFillConfig(
			sourceFiles: [$sourceFile],
			crmCompanyId: $this->myCompany->id,
			signersList: $this->signers,
			assigneeEntityId: $this->myCompany->id,
			representativeUser: $this->representative,
			companyProviderUid: $request->fields->getCompanyProviderUid(),
			regionDocumentType: $request->fields->getRegionDocumentType(),
			hcmLinkCompanyId: $this->hcmlinkCompany?->id ?? null,
			reviewerUser: $this->reviewer,
			editorUser: $this->editor,
			responsibleUser: $this->responsible,
			externalSettings: $externalSettings,
			language: $request->fields->getLanguage(),
		);

		return new Main\Result();
	}
}
