<?php

namespace Bitrix\Sign\Operation\Document\Template\Onboarding;

use Bitrix\Sign\Contract\Operation;

class GetOrCreateCompanyForDemoSigning implements Operation
{
	private ?string $companyUid = null;
	private ?int $companyEntityId = null;
	private readonly int $currentUserId;

	public function __construct(
		int $currentUserId,
	)
	{
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
		// Try to find suitable company first
		$operation = new GetFirstSuitableCompany();
		$result = $operation->launch();

		if ($result->isSuccess())
		{
			$this->companyUid = $operation->getCompanyUid();
			$this->companyEntityId = $operation->getCompanyEntityId();

			return $result;
		}

		// Create new company if no suitable found
		$operation = new CreateTestCompany($this->currentUserId);
		$result = $operation->launch();
		$this->companyUid = $operation->getCompanyUid();
		$this->companyEntityId = $operation->getCompanyEntityId();

		return $result;
	}
}
