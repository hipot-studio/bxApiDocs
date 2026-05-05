<?php

namespace Bitrix\Disk\Document\OnlyOffice\Cloud;

use Bitrix\Disk\Document\OnlyOffice\Configuration;
use Bitrix\Main\Application;
use Bitrix\Main\Result;

final class LimitInfo extends BaseSender
{
	protected const CACHE_CLIENT_LIMIT_KEY = 'disk.documentproxyClientLimit';

	public function getClientLimit(): Result
	{
		$managedCache = Application::getInstance()->getManagedCache();
		// invalidate cache at every day 00:10 UTC
		$now = time() - 600;
		$cacheTtl = (int)ceil($now / 86400) * 86400 - $now;

		if ($cacheTtl > 0 && $managedCache->read($cacheTtl, LimitInfo::CACHE_CLIENT_LIMIT_KEY))
		{
			return (new Result())
				->setData([
					'limit' => $managedCache->get(LimitInfo::CACHE_CLIENT_LIMIT_KEY),
				]);
		}

		$clientId = (new Configuration())->getCloudRegistrationData()['clientId'];

		/** @see \Bitrix\DocumentProxy\Controller\LimitInfo::getClientLimitAction */
		$result = $this->performRequest('documentproxy.LimitInfo.getClientLimit', [
			'clientId' => $clientId,
		]);

		if ($result->isSuccess())
		{
			$managedCache->set(LimitInfo::CACHE_CLIENT_LIMIT_KEY, $result->getData()['limit'] ?? null);
		}

		return $result;
	}

	public static function invalidateClientLimitCache(): void
	{
		Application::getInstance()->getManagedCache()->clean(LimitInfo::CACHE_CLIENT_LIMIT_KEY);
	}
}