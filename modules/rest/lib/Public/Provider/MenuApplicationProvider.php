<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\Internal\Access\LegacyAppAccessChecker;
use Bitrix\Rest\Internal\Repository\Application\AppAccessRepository;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Public\Provider\Dto\MenuApplicationData;

final class MenuApplicationProvider
{
	public function __construct(
		private readonly AppRepository $repository = new AppRepository(),
		private readonly AppAccessRepository $accessRepository = new AppAccessRepository(),
	)
	{
	}

	/** @return list<MenuApplicationData> */
	public function getForWeb(int $userId, int $appId = 0): array
	{
		return $this->getList($userId, false, $appId);
	}

	/** @return list<MenuApplicationData> */
	public function getForMobile(int $userId): array
	{
		return $this->getList($userId, true);
	}

	/** @return list<MenuApplicationData> */
	private function getList(int $userId, bool $mobile, int $appId = 0): array
	{
		$applications = $this->repository->findMenuApplications($mobile, $appId);
		if ($applications->isEmpty())
		{
			return [];
		}

		$accessCodes = $this->accessRepository->getCodesForApps($applications->getIdList());
		$accessChecker = new LegacyAppAccessChecker($userId);
		$result = [];
		foreach ($applications as $application)
		{
			if (
				(!$mobile && $application->getCode() === \CRestUtil::BITRIX_1C_APP_CODE)
				|| !$accessChecker->canAccessMenu($accessCodes[$application->getId()] ?? [])
			)
			{
				continue;
			}

			$menuName = $application->getLang() !== null ? $application->getLang()->getMenuName() : '';
			$defaultName = $application->getLangDefault() !== null ? $application->getLangDefault()->getMenuName() : '';
			$licenseName = $application->getLangLicense() !== null ? $application->getLangLicense()->getMenuName() : '';
			if ($menuName === '' && $defaultName === '' && $licenseName === '' && !$mobile)
			{
				$application->fillLangAll();
				$names = [];
				foreach ($application->getLangAll() ?? [] as $language)
				{
					if ($language->getMenuName() !== '')
					{
						$names[$language->getLanguageId()] = $language->getMenuName();
					}
				}
				$lang = in_array(LANGUAGE_ID, ['ru', 'en', 'de'], true)
					? LANGUAGE_ID
					: Loc::getDefaultLang(LANGUAGE_ID)
				;
				if (!empty($names[$lang]))
				{
					$menuName = $names[$lang];
				}
				elseif (!empty($names['en']))
				{
					$menuName = $names['en'];
				}
				elseif (!empty($names))
				{
					$menuName = reset($names);
				}
			}
			$hasApplicationPage = !empty($menuName) || !empty($defaultName) || !empty($licenseName);
			if ($menuName === '' || $menuName === null)
			{
				$menuName = $defaultName;
			}
			if ($menuName === '' || $menuName === null)
			{
				$menuName = $licenseName;
			}
			if ($menuName !== '' && $menuName !== null)
			{
				$result[] = new MenuApplicationData(
					(int)$application->getId(),
					(string)$application->getCode(),
					(string)$application->getClientId(),
					$menuName,
					$hasApplicationPage,
				);
			}
		}

		return $result;
	}
}
