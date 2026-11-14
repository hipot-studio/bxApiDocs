<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Rest\Internal\Entity\Application\AppAttributeCode;
use Bitrix\Rest\Internal\Repository\Application\AppAttributeRepository;
use Bitrix\Rest\Public\Provider\Dto\ApplicationData;

final class AppSystemUserDecisionService
{
	private const DECISION_YES = 'Y';
	private const DECISION_NO = 'N';

	public function __construct(
		private readonly AppAttributeRepository $repository = new AppAttributeRepository(),
	)
	{
	}

	public function saveInstallDecision(int $appId, ?ApplicationData $appBeforeInstall, bool $createSystemUser): void
	{
		if ($appBeforeInstall?->isActive() === true)
		{
			return;
		}

		$this->repository->saveSystemValue(
			$appId,
			AppAttributeCode::CreateSystemUser,
			$createSystemUser && $this->isCreationEnabled() ? self::DECISION_YES : self::DECISION_NO,
		);
	}

	public function isCreationEnabled(): bool
	{
		return Option::get('rest', 'allow_create_sys_user', 'N') === 'Y';
	}

	public function canCreateSystemUser(int $appId): bool
	{
		if (!$this->isCreationEnabled())
		{
			return false;
		}

		$decision = $this->repository->getByAppIdAndCode($appId, AppAttributeCode::CreateSystemUser->value);

		return $decision === null || $decision->getValue() === self::DECISION_YES;
	}
}
