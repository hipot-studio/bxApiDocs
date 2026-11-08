<?php

namespace Bitrix\Disk\Document\OnlyOffice\Cloud;

use Bitrix\Disk\Document\OnlyOffice\Configuration;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class LimitInfo extends BaseSender
{
	protected const CACHE_CLIENT_LIMIT_KEY = 'disk.documentproxyClientLimit';
	protected const CACHE_CLIENT_LIMIT_FAILURE_KEY = 'disk.documentproxyClientLimitFailure';
	protected const CACHE_CLIENT_LIMIT_FAILURE_TTL = 5 * 60;

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
		if ($managedCache->read(self::CACHE_CLIENT_LIMIT_FAILURE_TTL, self::CACHE_CLIENT_LIMIT_FAILURE_KEY))
		{
			return (new Result())->addError(new Error('Client limit is temporarily unavailable.'));
		}

		$clientId = (new Configuration())->getCloudRegistrationData()['clientId'];

		/** @see \Bitrix\DocumentProxy\Controller\LimitInfo::getClientLimitAction */
		$result = $this->performRequest('documentproxy.LimitInfo.getClientLimit', [
			'clientId' => $clientId,
		]);

		if ($result->isSuccess())
		{
			$managedCache->clean(self::CACHE_CLIENT_LIMIT_FAILURE_KEY);
			$managedCache->set(LimitInfo::CACHE_CLIENT_LIMIT_KEY, $result->getData()['limit'] ?? null);
		}
		else
		{
			$managedCache->set(self::CACHE_CLIENT_LIMIT_FAILURE_KEY, true);
		}

		return $result;
	}

	public static function invalidateClientLimitCache(): void
	{
		$managedCache = Application::getInstance()->getManagedCache();
		$managedCache->clean(self::CACHE_CLIENT_LIMIT_KEY);
		$managedCache->clean(self::CACHE_CLIENT_LIMIT_FAILURE_KEY);
	}
}
