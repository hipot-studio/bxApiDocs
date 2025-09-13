<?php

use Bitrix\Intranet;
use Bitrix\Intranet\Internal\Integration\Timeman\WorkTime;
use Bitrix\Main\Web\Uri;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

class IntranetAvatarWidget extends \CBitrixComponent
{
	public function executeComponent()
	{
		$user = new Intranet\User();

		$this->arResult['skeleton'] = (new Intranet\User\Widget())->getSkeletonConfiguration();
		$this->arResult['avatar'] = Uri::urnEncode((new Intranet\User\Widget\Content\Main($user))->getUserPhotoSrc());
		$this->arResult['userRole'] = $user->getUserRole()->value;
		$this->arResult['userId'] = $user->getId();
		$this->arResult['signDocumentsCounter'] = $this->getSignDocumentsCounter();
		$this->arResult['signDocumentsPullEventName'] = Intranet\User\Widget\Content\SignDocuments::getCounterEventName();
		$this->arResult['workTimeData'] = $this->getWorkTimeData($user->getId());

		$this->includeComponentTemplate();
	}

	private function getSignDocumentsCounter(): int
	{
		if (!Intranet\User\Widget\Content\SignDocuments::isSignDocumentAvailable())
		{
			return 0;
		}

		return Intranet\User\Widget\Content\SignDocuments::getCount();
	}

	private function getWorkTimeData(int $currentUserId): array
	{
		$cache = new \CPHPCache;

		$cacheTtl = 86400;
		$cacheId = 'timeman-work-time-data-' . $currentUserId;
		$cacheDir = '/timeman/work-time-data/' . $currentUserId;

		if ($cache->initCache($cacheTtl, $cacheId, $cacheDir))
		{
			return $cache->getVars();
		}

		$cache->startDataCache();

		$workTimeAvailable = WorkTime::canUse();

		$workTimeState = '';
		$workTimeAction = '';
		if ($workTimeAvailable)
		{
			$timeManUser = \CTimeManUser::instance();
			$workTimeState = $timeManUser->state();

			if ($workTimeState === 'CLOSED')
			{
				$workTimeAction = $timeManUser->openAction();
				$workTimeAction = ($workTimeAction === false) ? '' : $workTimeAction;
			}
		}

		$workTimeData = [
			'workTimeAvailable' => $workTimeAvailable,
			'workTimeState' => $workTimeState,
			'workTimeAction' => $workTimeAction,
		];

		$cache->endDataCache($workTimeData);

		return $workTimeData;
	}
}
