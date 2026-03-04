<?php

namespace Bitrix\Main\Security\Notifications;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\UpdateSystem\PortalInfo;

class VendorNotifier
{
	const CACHE_RULES_TTL = 86400;

	public static function refreshNotifications()
	{
		try
		{
			$lastTime = Option::get('main_sec', 'SEC_ACTUALIZE_VENDOR_NOTIFICATIONS', 0);

			if ((time() - $lastTime) < static::CACHE_RULES_TTL)
			{
				return;
			}

			$connection = Application::getConnection();

			// we don't want to do the same job twice
			if (!$connection->lock('SEC_ACTUALIZE_VENDOR_NOTIFICATIONS'))
			{
				return;
			}

			Option::set('main_sec', 'SEC_ACTUALIZE_VENDOR_NOTIFICATIONS', time());

			$newData = null;

			$dataToSend = (new PortalInfo())->getSystemInfo();

			// get actual rules
			$http = new HttpClient([
				'socketTimeout' => 5,
				'streamTimeout' => 5
			]);

			$uri = Application::getInstance()->getLicense()->getDomainStoreLicense().'/box/notification.php';

			$response = $http->post(
				$uri,
				$dataToSend
			);

			if ($http->getStatus() == 200 && !empty($response))
			{
				$newData = Json::decode($response);
			}

			//update db
			if ($newData !== null)
			{
				$tableName = VendorNotificationTable::getTableName();

				// remove current data
				$connection->truncateTable($tableName);

				// prepare new data
				if (!empty($newData))
				{
					$records = [];
					foreach ($newData as $dataItem)
					{
						if (!empty($dataItem['id']) && !empty($dataItem['data']['title']) && !empty($dataItem['data']['text']))
						{
							$dataItem['data'] = Json::encode($dataItem['data']);

							$records[] = "('" .
								$connection->getSqlHelper()->forSql($dataItem['id'])
								. "', '" . $connection->getSqlHelper()->forSql($dataItem['data'])
								. "')";
						}
					}

					if (!empty($records))
					{
						$recordsSql = join(", ", $records);

						// save new data
						$connection->query("INSERT INTO {$tableName} (VENDOR_ID, DATA) VALUES {$recordsSql}");

						// clean entity cache
						VendorNotificationTable::cleanCache();
					}
				}
			}

			$connection->unlock('SEC_ACTUALIZE_VENDOR_NOTIFICATIONS');
		}
		catch (\Throwable $e)
		{
			\CEventLog::log(
				\CEventLog::SEVERITY_SECURITY,
				'SECURITY_VENDOR_NOTIFICATION_EXCEPTION',
				'main',
				'FAIL_REFRESHING',
				'Can not refresh security vendor notifications: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString()
			);
		}
	}
}
