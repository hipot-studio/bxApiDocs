<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage rest
 * @copyright 2001-2016 Bitrix
 */

namespace Bitrix\Rest\OAuth;


use Bitrix\Main\Config\Option;
use Bitrix\Main;

class Engine
{
	protected $scope = array(
		"rest", "application"
	);

	protected $client = null;

	public function __construct()
	{
	}


	/**
	 * @return \Bitrix\Rest\OAuth\Client
	 */
	public function getClient()
	{
		if(!$this->client)
		{
			$this->client = new Client(
				$this->getClientId(),
				$this->getClientSecret(),
				$this->getLicense()
			);
		}

		return $this->client;
	}

	public function isRegistered()
	{
		return $this->getClientId() !== false;
	}

	public function getClientId()
	{
		return Option::get("rest", "service_client_id", false);
	}

	public function getClientSecret()
	{
		return Option::get("rest", "service_client_secret", false);
	}

	public function setAccess(array $accessParams): void
	{
		$connection = Main\Application::getInstance()->getConnection();
		$connection->startTransaction();
		try
		{
			$historyId = microtime(false);
			$url = (new Main\Web\Uri('/bitrix/admin/perfmon_table.php'))
				->addParams(
					[
						'lang' => LANGUAGE_ID,
						'table_name' => 'b_option',
						'f_MODULE_ID' => 'rest',
						'f_NAME' => $historyId . '%',
						'apply_filter' => 'Y',
					]
				);
			\CEventLog::Log(
				\CEventLog::SEVERITY_CRITICAL,
				'REST_OAUTH_REGISTER',
				'rest',
				'oauth',
				$url->getUri()
			);

			Option::set('rest', $historyId . ' service_client_id',  Option::get('rest', 'service_client_id', ''));
			Option::set('rest', $historyId . ' service_client_secret', Option::get('rest', 'service_client_secret', ''));

			Option::set('rest', 'service_client_id', $accessParams['client_id']);
			Option::set('rest', 'service_client_secret', $accessParams['client_secret']);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		$this->client = null;
	}

	public function clearAccess()
	{
		$this->setAccess(array(
			"client_id" => false,
			"client_secret" => false,
		));

		$this->client = null;
	}

	public function getLicense()
	{
		return LICENSE_KEY;
	}
}
