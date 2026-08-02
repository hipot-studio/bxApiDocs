<?php
namespace Bitrix\ImConnector;

use \Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);
Library::loadMessages();

/**
 * Class for sending messages for the server of connectors.
 *
 * @see \Bitrix\ImConnectorServer\Connectors\Facebook\Lib::delUserActive
 * @method Result delUserActive($idUser)
 *
 * @see \Bitrix\ImConnectorServer\Connectors\Facebook\Lib::delPageActive
 * @method Result delPageActive($idPage, $local = false)
 *
 * @see \Bitrix\ImConnectorServer\Connectors\Facebook\Lib::authorizationPage
 * @method Result authorizationPage($idPage, array $params = [])
 *
 * @see \Bitrix\ImConnectorServer\Connectors\Connector::deleteConnector
 * @method Result deleteConnector($sendDeactivateConnector = false)
 *
 * @see \Bitrix\ImConnectorServer\Connectors\Facebook\LibInstagram::getAuthorizationInformation
 * @method Result getAuthorizationInformation($returnUrl = '')
 *
 * Dynamic methods:
 *
 * @method Result register(array $data = [])
 * @method Result update(array $data = [])
 * @method Result delete(array $data = [])
 *
 * @method Result sendStatusWriting(array $data)
 * @method Result sessionStart(array $data)
 * @method Result sessionFinish(array $data)
 *
 * @method Result sendMessage(array $data)
 * @method Result updateMessage(array $data)
 * @method Result deleteMessage(array $data)
 *
 * @method Result registerEshop(array $data)
 *
 * @see \Bitrix\ImConnectorServer\Connector::infoConnectorsLine
 * @method static Result infoConnectorsLine(int $lineId)
 *
 * @see \Bitrix\ImConnectorServer\Connector::saveDomainSite
 * @method static Result saveDomainSite(string $publicUrl)
 *
 * @see \Bitrix\ImConnectorServer\Connectors\Wazzup::getOauthUrl
 * @method Result getOauthUrl()
 *
 * @see \Bitrix\ImConnectorServer\Connectors\Wazzup::handleOauthCallback
 * @method Result handleOauthCallback(string $code, ?string $state = null)
 *
 * @see \Bitrix\ImConnectorServer\Connectors\Wazzup::getChannelsList
 * @method Result getChannelsList(?string $apiKey = null)
 *
 * @package Bitrix\ImConnector
 * @final
 */
final class Output
{
	/**
	 * Static line commands that are grouped by connector proxying for the imconnectorserver provider.
	 */
	private const LINE_GROUP_COMMANDS = [
		'infoconnectorsline',
		'deleteline',
	];

	/*** @var Result */
	protected $result;

	/**
	 * @var Provider\Base\Output|Provider\ImConnectorServer\Output|Provider\LiveChat\Output|Provider\Network\Output|Provider\Custom\Output
	 */
	protected $provider;

	/**
	 * Output constructor.
	 * @param string $connector ID connector.
	 * @param int|bool $line ID open line.
	 * @param bool $ignoreDeactivatedConnector
	 */
	public function __construct($connector, $line = false, $ignoreDeactivatedConnector = false)
	{
		$this->result = new Result();

		if(
			$connector !== 'all'
			&&
			(
				!empty($ignoreDeactivatedConnector) ||
				Connector::isConnector($connector)
			)
		)
		{
			$provider = Provider::getProviderForConnectorOutput($connector, $line);

			if ($provider->isSuccess())
			{
				/** @var Provider\Base\Output $this->provider */
				$this->provider = $provider->getResult();
			}
			else
			{
				$this->result->addErrors($provider->getErrors());
			}
		}
		elseif ($connector == 'all')
		{
			$this->result->addError(new Error(
				Loc::getMessage('IMCONNECTOR_ERROR_PROVIDER_GENERAL_REQUEST_NOT_DYNAMIC_METHOD'),
				Library::ERROR_IMCONNECTOR_PROVIDER_GENERAL_REQUEST_NOT_DYNAMIC_METHOD,
				__METHOD__,
				$connector
			));
		}
		else
		{
			$this->result->addError(new Error(
				Loc::getMessage('IMCONNECTOR_ERROR_PROVIDER_NO_ACTIVE_CONNECTOR'),
				Library::ERROR_IMCONNECTOR_PROVIDER_NO_ACTIVE_CONNECTOR,
				__METHOD__,
				$connector
			));
		}
	}

	/**
	 * Magic method for handling dynamic methods.
	 *
	 * @param string $name The name of the called method.
	 * @param array $arguments The set of parameters passed to the method.
	 * @return Result
	 */
	public function __call($name, $arguments): Result
	{
		$result = clone $this->result;

		if($result->isSuccess())
		{
			$result = $this->provider->call($name, $arguments);
		}

		return $result;
	}

	/**
	 * Static magic method.
	 * Caching is used for a number of methods.
	 *
	 * @param string $name The name of the called method.
	 * @param array $arguments The set of parameters passed to the method.
	 * @return Result
	 */
	public static function __callStatic($name, $arguments)
	{
		$result = new Result();
		$resultsCall = [];

		$isLineGroupCommand = in_array(mb_strtolower($name), self::LINE_GROUP_COMMANDS, true);

		// A line command is answered only by the providers actually present on the line, so an
		// optional module missing for an unrelated provider cannot flip the whole command to failure.
		// The line connectors are read once here and reused for both provider selection and grouping.
		$lineId = 0;
		$lineConnectorIds = [];
		if ($isLineGroupCommand)
		{
			$lineId = (int)($arguments[0] ?? 0);
			$lineConnectorIds = self::getLineConnectorIds($lineId);
			// A connector present on the line that fails to resolve to a provider must poison the whole
			// line command: otherwise the missing data would read as a successful partial answer and the
			// caller would prune that channel from the read-model.
			$providersResult = Provider::getProvidersForLineOutput($lineConnectorIds);
			if (!$providersResult->isSuccess())
			{
				$result->addErrors($providersResult->getErrors());
			}
			$providers = $providersResult->getResult() ?: [];
		}
		else
		{
			$providers = Provider::getAllProviderForAllOutput();
		}

		foreach ($providers as $provider)
		{
			if ($isLineGroupCommand && $provider instanceof Provider\ImConnectorServer\Output)
			{
				// The imconnectorserver provider no longer sends a single CONNECTOR='all' lump:
				// its connectors of the line are split into groups by needProxy() and each
				// non-empty group is sent as one request carrying the connector set and the line id.
				$resultCall = self::callImConnectorServerGrouped($name, $lineId, $lineConnectorIds);
			}
			else
			{
				$resultCall = $provider->call($name, $arguments);
			}

			if (!empty($resultCall->getData()))
			{
				$resultsCall = array_merge($resultsCall, $resultCall->getData());
			}

			if (!$resultCall->isSuccess())
			{
				$result->addErrors($resultCall->getErrors());
			}
		}

		$result->setData($resultsCall);

		return $result;
	}

	/**
	 * Runs a line command (infoConnectorsLine/deleteLine) for the imconnectorserver provider by
	 * splitting the line connectors into two groups by their proxying flag and sending one request
	 * per non-empty group with the group's connector set and the line id. The connector ids are read
	 * once by the caller and passed in, so the grouping does not re-query the line statuses.
	 *
	 * @param string $command Command name.
	 * @param int $lineId Open line ID.
	 * @param string[] $connectorIds Connector ids registered on the line.
	 * @return Result
	 */
	private static function callImConnectorServerGrouped(string $command, int $lineId, array $connectorIds): Result
	{
		$result = new Result();
		$data = [];

		$groups = self::splitConnectorsByProxying($connectorIds);

		foreach ($groups as $groupConnectorIds)
		{
			if (empty($groupConnectorIds))
			{
				continue;
			}

			$providerResult = Provider::getProviderForConnectorSetOutput(implode(',', $groupConnectorIds), $lineId);
			if (!$providerResult->isSuccess())
			{
				$result->addErrors($providerResult->getErrors());
				continue;
			}

			/** @var Provider\Base\Output $provider */
			$provider = $providerResult->getResult();
			$resultCall = $provider->call($command, [$lineId]);

			if (!empty($resultCall->getData()))
			{
				$data = array_merge($data, $resultCall->getData());
			}

			if (!$resultCall->isSuccess())
			{
				$result->addErrors($resultCall->getErrors());
			}
		}

		$result->setData($data);

		return $result;
	}

	/**
	 * Reads the connector ids registered on the line once, for reuse by both provider selection
	 * and the imconnectorserver grouping of a line command.
	 *
	 * @param int $lineId Open line ID.
	 * @return string[]
	 */
	private static function getLineConnectorIds(int $lineId): array
	{
		$connectorIds = [];
		foreach (Status::getInstanceAllConnector($lineId) as $status)
		{
			$connectorIds[] = (string)$status->getConnector();
		}

		return $connectorIds;
	}

	/**
	 * Splits a list of connector ids into the proxy/local buckets by their proxying flag.
	 * Only connectors served by the imconnectorserver provider take part; connectors of other
	 * providers are ignored here (they are handled through their own provider path).
	 *
	 * @param string[] $connectorIds Connector ids.
	 * @return array{proxy: string[], local: string[]}
	 */
	private static function splitConnectorsByProxying(array $connectorIds): array
	{
		$groups = ['proxy' => [], 'local' => []];

		foreach ($connectorIds as $connectorId)
		{
			$connectorId = (string)$connectorId;
			if ($connectorId === '' || !Provider::isImConnectorServerConnector($connectorId))
			{
				continue;
			}

			$bucket = self::connectorNeedProxy($connectorId) ? 'proxy' : 'local';
			$groups[$bucket][] = $connectorId;
		}

		return $groups;
	}

	/**
	 * @param string $connectorId Connector id.
	 * @return bool
	 */
	private static function connectorNeedProxy(string $connectorId): bool
	{
		return Connector::initConnectorHandler($connectorId)->needProxy();
	}

	/**
	 * The removal of the open line of this website from the remote server connectors.
	 *
	 * @param string $lineId ID of the deleted lines.
	 * @return Result
	 */
	public static function deleteLine($lineId): Result
	{
		// The grouped deleteLine enumerates the line connectors from Status, so the request to the
		// server must be built before the local Status is wiped — otherwise no connector set is
		// collected and the server keeps the line's connectors orphaned.
		$result = self::__callStatic('deleteLine', [$lineId]);

		Status::deleteAll((int)$lineId);

		return $result;
	}
}
