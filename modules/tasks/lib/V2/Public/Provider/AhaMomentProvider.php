<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Integration\Bitrix24\Portal;
use Bitrix\Tasks\V2\Internal\Repository\UserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\OptionDictionary;

class AhaMomentProvider
{
	public function __construct(
		private readonly UserOptionRepositoryInterface $userOptionRepository
	)
	{
	}

	public function get(int $userId): array
	{
		$isOldPortalUser = $this->isOldPortalUser();

		$ahaResponsibleMany = !$this->isShown($userId, OptionDictionary::AhaResponsibleMany);
		$ahaTaskSettingsShow = !$this->isShown($userId, OptionDictionary::AhaTaskSettingsMessage);
		$ahaAuditorsCompactShow = !$this->isShown($userId, OptionDictionary::AhaAuditorsInCompactForm);
		$ahaResultFromMessageShow = !$this->isShown($userId, OptionDictionary::AhaResultFromMessage);
		$ahaRequiredResultCreatorShow = !$this->isShown($userId, OptionDictionary::AhaRequiredResultCreator);
		$ahaRequiredResultResponsibleShow = !$this->isShown($userId, OptionDictionary::AhaRequiredResultResponsible);
		$ahaStartTimeTrackingShow = !$this->isShown($userId, OptionDictionary::AhaStartTimeTracking);
		$ahaTaskChatShow = $isOldPortalUser && !$this->isShown($userId, OptionDictionary::AhaTaskChat);

		return [
			OptionDictionary::AhaResponsibleMany->value => $ahaResponsibleMany,
			OptionDictionary::AhaTaskSettingsMessage->value => $ahaTaskSettingsShow,
			OptionDictionary::AhaAuditorsInCompactForm->value => $ahaAuditorsCompactShow,
			OptionDictionary::AhaResultFromMessage->value => $ahaResultFromMessageShow,
			OptionDictionary::AhaRequiredResultCreator->value => $ahaRequiredResultCreatorShow,
			OptionDictionary::AhaRequiredResultResponsible->value => $ahaRequiredResultResponsibleShow,
			OptionDictionary::AhaStartTimeTracking->value => $ahaStartTimeTrackingShow,
			OptionDictionary::AhaTaskChat->value => $ahaTaskChatShow,
			OptionDictionary::AhaTaskImportantMessages->value => false,
		];
	}

	private function isShown(int $userId, OptionDictionary $optionDictionary): bool
	{
		return $this->userOptionRepository->isSet(
			optionDictionary: $optionDictionary,
			userId: $userId,
		);
	}

	private function isOldPortalUser(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		$portalCreateDate = (new Portal())->getCreationDateTime();
		$userDateRegister = CurrentUser::get()->getDateRegister();
		$suitablePortalCreationDate = Loader::includeModule('bitrix24')
			? new DateTime('2025-11-26', 'Y-m-d')
			: new DateTime('2026-01-21', 'Y-m-d')
		;

		return ($portalCreateDate?->getTimestamp() <= $suitablePortalCreationDate->getTimestamp())
			&& (!$userDateRegister || $userDateRegister->getTimestamp() <= $suitablePortalCreationDate->getTimestamp())
		;
	}
}
