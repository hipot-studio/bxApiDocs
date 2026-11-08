<?php

namespace Bitrix\HumanResources\Service\HcmLink;

use Bitrix\HumanResources\Contract;
use Bitrix\HumanResources\Repository\HcmLink\ExclusionRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class CompanyDisconnectService
{
	public const ERROR_COMPANY_NOT_FOUND = 'COMPANY_NOT_FOUND';

	private readonly ExclusionRepository $exclusionRepository;

	public function __construct(
		private readonly Contract\Repository\HcmLink\CompanyRepository $companyRepository,
		private readonly Contract\Repository\HcmLink\FieldRepository $fieldRepository,
		?ExclusionRepository $exclusionRepository = null,
	)
	{
		$this->exclusionRepository = $exclusionRepository ?? Container::getHcmLinkExclusionRepository();
	}

	public function disconnect(int $companyId): Result
	{
		$result = new Result();

		$company = $this->companyRepository->getById($companyId);
		if ($company === null)
		{
			return $result->addError(new Error('Company not found', self::ERROR_COMPANY_NOT_FOUND));
		}

		// The deletions form one integration removal: a failure between them would leave a company
		// without its fields, a state nothing else in the domain produces. Exclusions belong to the
		// same removal - reconnecting gives a new company id, and the records left behind would
		// never be reachable again.
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->fieldRepository->deleteByCompany($company->id);
			$this->exclusionRepository->deleteByCompanyId($company->id);
			$this->companyRepository->delete($company->id);
		}
		catch (\Throwable $exception)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable $rollbackFailure)
			{
				// Inside somebody else's transaction the rollback itself throws, and an unguarded call
				// would replace the real cause with a complaint about the rollback. The data is safe
				// either way - the outer transaction is the one that decides - so the original failure
				// is what has to survive.
			}

			throw $exception;
		}

		$connection->commitTransaction();

		return $result;
	}
}
