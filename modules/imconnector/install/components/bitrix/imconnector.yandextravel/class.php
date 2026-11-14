<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\ImConnector\Limit;
use Bitrix\ImConnector\Output;
use Bitrix\ImConnector\Status;
use Bitrix\ImConnector\Library;
use Bitrix\ImConnector\Connector;
use Bitrix\ImConnector\Model\StatusConnectorsTable;

class ImConnectorYandextravel extends \CBitrixComponent
{
	public const HELPDESK_CODE = '28803170';

	private ?string $callbackOrigin = null;

	private $cacheId;
	private $connector = 'yandextravel';
	private $error = [];
	private $messages = [];
	/** @var Output */
	private $connectorOutput;
	/** @var Status */
	private $status;
	protected $pageId = 'page_yabt';

	/**
	 * Whether the connector is offered on this portal at all.
	 *
	 * Connector::isConnector() only tells that the connector is supported by the server: it reads the
	 * module-wide connector list, which knows nothing about the rollout flag and the region. Both are
	 * applied when the catalog is built, so without this check the settings page would still open by its
	 * direct URL and let the connector be connected where it is not offered.
	 *
	 * @return bool
	 */
	private function isConnectorAvailable(): bool
	{
		if (array_key_exists($this->connector, Connector::getListConnector()))
		{
			return true;
		}

		// Withdrawing the connector from the catalog must not lock an operator out of a line that is
		// already connected, nor out of a connection left unfinished: the first POST of the form already
		// makes the line active, while the full status appears only after OAuth and register(). This page
		// is where both are completed or disconnected.
		$status = Status::getInstance($this->connector, (int)$this->arParams['LINE']);

		return $status->isStatus() || $status->getActive();
	}

	/**
	 * Check the connection of the necessary modules.
	 * @return bool
	 */
	protected function checkModules()
	{
		if (Loader::includeModule('imconnector'))
		{
			return true;
		}
		else
		{
			ShowError(Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_MODULE_NOT_INSTALLED_MSGVER_1'));
			return false;
		}
	}

	protected function initialization()
	{
		$this->connectorOutput = new Output($this->connector, (int)$this->arParams['LINE']);

		$this->status = Status::getInstance($this->connector, (int)$this->arParams['LINE']);

		$this->arResult["STATUS"] = $this->status->isStatus();
		$this->arResult["ACTIVE_STATUS"] = $this->status->getActive();
		$this->arResult["CONNECTION_STATUS"] = $this->status->getConnection();
		$this->arResult["REGISTER_STATUS"] = $this->status->getRegister();
		$this->arResult["ERROR_STATUS"] = $this->status->getError();
		$this->arResult['CAN_USE_CONNECTION'] = Limit::canUseConnector($this->connector);
		$this->arResult['INFO_HELPER_LIMIT'] = Limit::getIdInfoHelperConnector($this->connector);
		$this->arResult['HELPDESK_CODE'] = self::HELPDESK_CODE;

		$this->cacheId = Connector::getCacheIdConnector($this->arParams['LINE'], $this->connector);
	}

	/**
	 * Reset cache
	 */
	protected function cleanCache()
	{
		Connector::cleanCacheConnector($this->arParams['LINE'], $this->cacheId);
	}

	public function saveForm()
	{
		if ($this->request->isPost() && !empty($this->request[$this->connector. '_form']))
		{
			if (check_bitrix_sessid())
			{
				if ($this->request[$this->connector. '_active'] && empty($this->arResult["ACTIVE_STATUS"]))
				{
					$this->status->setActive(true);
					$this->arResult["ACTIVE_STATUS"] = true;

					$this->cleanCache();
				}

				if (!empty($this->arResult["ACTIVE_STATUS"]))
				{
					// One line == one Yandex account: no hotel/channel selection. Connecting (or an
					// explicit re-test) simply finalizes the OAuth-authorized account.
					if (isset($this->request[$this->connector. '_save']) || $this->request[$this->connector. '_tested'])
					{
						$this->finalizeConnection();
						$this->cleanCache();
					}

					if ($this->request[$this->connector. '_switch'])
					{
						// Operator confirmed moving the account here: free it from the other line(s),
						// then finalize the current one (the duplicate check now passes).
						$info = $this->connectorOutput->infoConnect();
						$ownerId = $info->isSuccess() ? (string)(((array)$info->getResult())['owner_id'] ?? '') : '';
						$freedLines = $ownerId !== '' ? $this->switchAccountFromOtherLines($ownerId) : [];

						$this->finalizeConnection();

						// A move that did not arrive is undone. Freeing the account and taking it are two
						// separate operations, and the second one fails for reasons that have nothing to do
						// with this line — the API is unreachable, a parallel connection holds the lifecycle
						// lock. Left as it is, that leaves the account bound to nothing: the lines it used to
						// serve are silent and this one is not connected either.
						if (empty($this->arResult['REGISTER_STATUS']))
						{
							$this->restoreAccountToLines($freedLines, $ownerId);
						}

						$this->cleanCache();
					}

					if ($this->request[$this->connector. '_del'])
					{
						$rawDelete = $this->connectorOutput->deleteConnector();

						if ($rawDelete->isSuccess())
						{
							Status::delete($this->connector, (int)$this->arParams['LINE']);
							$this->arResult["STATUS"] = false;
							$this->arResult["ACTIVE_STATUS"] = false;
							$this->arResult["CONNECTION_STATUS"] = false;
							$this->arResult["REGISTER_STATUS"] = false;
							$this->arResult["ERROR_STATUS"] = false;
							$this->arResult["PAGE"] = '';
						}
						else
						{
							$this->error[] = Loc::getMessage("IMCONNECTOR_COMPONENT_SETTINGS_NO_DISABLE");
						}

						$this->cleanCache();
					}
				}
			}
			else
			{
				$this->error[] = Loc::getMessage("IMCONNECTOR_COMPONENT_YANDEXTRAVEL_SESSION_HAS_EXPIRED_MSGVER_1");
			}
		}
	}

	public function constructionForm()
	{
		global $APPLICATION;

		$authInfo = $this->connectorOutput->getAuthorizationInformation();
		$hasOAuthTokens = false;

		if ($authInfo->isSuccess())
		{
			$authData = $authInfo->getResult();

			if (!empty($authData['SETTINGS']['access_token']) || !empty($authData['SETTINGS']['refresh_token']))
			{
				$hasOAuthTokens = true;
				$this->arResult['HAS_OAUTH_TOKENS'] = true;
			}
		}

		if ($hasOAuthTokens)
		{
			// The OAuth callback has already (re)written the server-side tokens and owner_id. Finalize when:
			//  - the line is not connected yet (first render after the popup, or a previous failure), or
			//  - the line is connected but was just re-authorized with a DIFFERENT Yandex account — the
			//    server-side owner_id then no longer matches the one persisted in the portal status, or
			//  - the line is connected on the portal but not on the server: a revoked grant or a
			//    re-authorization the operator walked away from leaves the portal showing a connection
			//    the server no longer routes to, and opening this page is when that can be noticed, or
			//  - both sides call the line connected but mean different bindings: the server holds one the
			//    status does not name, so what the page shows describes a connection that is gone.
			// Without the second case a re-OAuth to another account would leave portal status (owner_id/
			// account_name) stale, skip the duplicate check and let one account back two lines / desync UI.
			// finalizeConnection() re-runs the duplicate check and re-persists identity, keeping both sides
			// in sync. No hotel selection — the Yandex account itself is the line.
			$needFinalize = empty($this->arResult["STATUS"]);
			if (!$needFinalize)
			{
				$authorizedOwnerId = '';
				$serverBindingId = '';
				$registeredOnServer = true;
				$infoConnect = $this->connectorOutput->infoConnect();
				if ($infoConnect->isSuccess())
				{
					$info = (array)$infoConnect->getResult();
					$authorizedOwnerId = (string)($info['owner_id'] ?? '');
					$serverBindingId = (string)($info['binding_id'] ?? '');
					// Absent for a server that predates the flag: such a server keeps the old behaviour.
					$registeredOnServer = !isset($info['registered']) || $info['registered'] === 'Y';
				}
				$statusData = (array)$this->status->getData();
				$statusOwnerId = (string)($statusData['owner_id'] ?? '');
				// Compared only when both sides name a binding: a line connected before bindings were
				// tracked has none on either side, and one that predates only the status field is left to
				// the checks above rather than reconnected on sight.
				$statusBindingId = (string)($statusData['binding_id'] ?? '');
				$needFinalize =
					!$registeredOnServer
					|| ($authorizedOwnerId !== '' && $authorizedOwnerId !== $statusOwnerId)
					|| ($serverBindingId !== '' && $statusBindingId !== '' && $serverBindingId !== $statusBindingId)
				;
			}

			// Detected here, carried out only on request. Finalizing replaces the inbound webhook
			// subscription and rewrites the state of the line, and a plain GET of this page is something an
			// outside page can make the browser of a logged-in administrator do. So the divergence is only
			// reported to the template, which offers the operator a button; the work itself happens in
			// saveForm(), behind POST and check_bitrix_sessid(). After the OAuth popup the page submits
			// that same form on its own, so the operator still sees one uninterrupted connect flow.
			$this->arResult['NEEDS_FINALIZE'] = $needFinalize;
		}

		// Provide the OAuth url whenever the authorize button is rendered: when not connected, and
		// also on the edit page of a connected line (re-authorization / switching the account).
		if (empty($this->arResult["STATUS"]) || !empty($this->arResult["PAGE"]))
		{
			$this->requestOauthUrl();
		}

		$this->arResult["NAME"] = Connector::getNameConnectorReal($this->connector);

		$this->arResult["URL"]["INDEX"] = $APPLICATION->GetCurPageParam($this->pageId . "=index", array($this->pageId, "open_block", "action"));
		$this->arResult["URL"]["MASTER"] = $APPLICATION->GetCurPageParam($this->pageId . "=master", array($this->pageId, "open_block", "action"));
		$this->arResult["URL"]["MASTER_NEW"] = $APPLICATION->GetCurPageParam($this->pageId . "=master_new", array($this->pageId, "open_block", "action"));
		$this->arResult["URL"]["DELETE"] = $APPLICATION->GetCurPageParam("", array($this->pageId, "open_block", "action"));
		$this->arResult["URL"]["SIMPLE_FORM"] = $APPLICATION->GetCurPageParam($this->pageId . "=simple_form", array($this->pageId, "open_block", "action"));
		$this->arResult["URL"]["SIMPLE_FORM_EDIT"] = $APPLICATION->GetCurPageParam($this->pageId . "=simple_form", array($this->pageId, "open_block", "action"));

		if ($this->arResult["ACTIVE_STATUS"])
		{
			if ($this->arResult["STATUS"])
			{
				$cache = Cache::createInstance();

				if ($cache->initCache(Library::CACHE_TIME_COMPONENT, $this->cacheId, Library::CACHE_DIR_COMPONENT))
				{
					$this->arResult["INFO_CONNECTION"] = $cache->getVars();
				}
				elseif ($cache->startDataCache())
				{
					$infoConnect = $this->connectorOutput->infoConnect();
					if ($infoConnect->isSuccess())
					{
						// infoConnect() packs its payload via setResult(): read it through getResult().
						$infoConnectData = (array)$infoConnect->getResult();

						// Connected Yandex account label: prefer the name persisted in the status,
						// fall back to infoConnect()'s login/owner.
						$accountName = (string)(((array)$this->status->getData())['account_name'] ?? '');
						$this->arResult["INFO_CONNECTION"] = [
							'NAME' => $accountName !== '' ? $accountName : (string)($infoConnectData['name'] ?? ''),
						];

						$cache->endDataCache($this->arResult["INFO_CONNECTION"]);
					}
					else
					{
						$this->arResult["INFO_CONNECTION"] = array();
						$cache->abortDataCache();
					}
				}

				$uri = new Uri($this->arResult["URL"]["DELETE"]);
				$uri->addParams(array('action' => 'disconnect'));
				$this->arResult["URL"]["DELETE"] = $uri->getUri();

				$uri = new Uri($this->arResult["URL"]["SIMPLE_FORM_EDIT"]);
				$uri->addParams(array('action' => 'edit'));
				$this->arResult["URL"]["SIMPLE_FORM_EDIT"] = $uri->getUri();
			}
			else
			{
				$uri = new Uri($this->arResult["URL"]["SIMPLE_FORM_EDIT"]);
				$uri->addParams(array('action' => 'connect'));
				$this->arResult["URL"]["SIMPLE_FORM_EDIT"] = $uri->getUri();
			}
		}

		$this->arResult["CONNECTOR"] = $this->connector;
	}

	/**
	 * Finalizes the connection for the OAuth-authorized Yandex account: forbids binding the same
	 * account to two lines, runs the portal test-connect and subscribes the inbound webhook.
	 */
	private function finalizeConnection(): void
	{
		$ownerId = '';
		$accountName = '';
		$info = $this->connectorOutput->infoConnect();
		if ($info->isSuccess())
		{
			$infoData = (array)$info->getResult();
			$ownerId = (string)($infoData['owner_id'] ?? '');
			$accountName = (string)($infoData['name'] ?? '');
		}

		if ($ownerId !== '')
		{
			$otherLines = $this->findOwnerLines($ownerId);
			if (!empty($otherLines))
			{
				// Account already bound to another line. Don't hard-fail: surface a warning and let
				// the operator move it here via the "switch" action (see saveForm), instead of
				// manually disconnecting the other line first.
				$this->arResult['DUPLICATE_OWNER'] = true;
				$this->arResult['DUPLICATE_LINES'] = array_values($this->visibleLineNames($otherLines));

				// The connector-server already dropped the registration of the re-authorized line,
				// so the portal must not keep showing the previous account as connected.
				$this->status->setRegister(false);
				$this->arResult['REGISTER_STATUS'] = false;
				$this->arResult['STATUS'] = false;

				return;
			}
		}

		$testConnect = $this->connectorOutput->testConnect();
		if (!$testConnect->isSuccess())
		{
			foreach ($testConnect->getErrors() as $error)
			{
				$code = $error->getCode();
				if ($code === 'CONNECTOR_SETTINGS_INCORRECT')
				{
					$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_SETTINGS_INCORRECT');
				}
				elseif ($code === 'CONNECTOR_NO_ANSWER_CLIENT')
				{
					$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_NO_ANSWER_CLIENT_MSGVER_1');
				}
				else
				{
					$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_NO_CONNECT_MSGVER_1');
				}
				break;
			}

			$this->status->setConnection(false);
			$this->arResult['CONNECTION_STATUS'] = false;
			$this->status->setRegister(false);
			$this->arResult['REGISTER_STATUS'] = false;
			$this->arResult['STATUS'] = false;

			return;
		}

		$this->status->setConnection(true);
		$this->arResult['CONNECTION_STATUS'] = true;

		// Subscribe the inbound webhook on the connector-server; without register() incoming
		// messages are not delivered.
		$register = $this->connectorOutput->register();
		if (!$register->isSuccess())
		{
			foreach ($register->getErrors() as $error)
			{
				if ($error->getCode() === 'CONNECTOR_YANDEXTRAVEL_ACCOUNT_ALREADY_BOUND')
				{
					// The server refused the binding: another line got this account registered first
					// (see Yandextravel::register()).
					$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_ACCOUNT_ALREADY_BOUND');

					continue;
				}

				if ($error->getCode() === 'CONNECTOR_YANDEXTRAVEL_LIFECYCLE_LOCK_BUSY')
				{
					// The server refused to touch the shared webhook subscription while a parallel
					// connection or disconnection is running: this one is simply repeated.
					$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_LIFECYCLE_LOCK_BUSY');

					continue;
				}

				// The connector server sends the error code only, its message does not cross the wire.
				$message = (string)$error->getMessage();
				$this->error[] = $message !== ''
					? $message
					: Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_REGISTER_FAILED');
			}

			$this->status->setRegister(false);
			$this->arResult['REGISTER_STATUS'] = false;
			$this->arResult['STATUS'] = false;

			return;
		}

		$this->status->setRegister(true);
		$this->arResult['REGISTER_STATUS'] = true;
		$this->arResult['STATUS'] = true;
		$this->status->setError(false);
		$this->arResult['ERROR_STATUS'] = false;
		$this->arResult['PAGE'] = '';

		// Persist account identity for the duplicate check and the connection-info block, plus the id of
		// the binding just made: it is what lets a deactivation sent by the server be told apart from one
		// about a connection that has since been replaced.
		// @see \Bitrix\ImConnector\Provider\ImConnectorServer\Input::deactivateConnector()
		$this->status->setData([
			'owner_id' => $ownerId,
			'account_name' => $accountName,
			'binding_id' => (string)(((array)$register->getResult())['binding_id'] ?? ''),
		]);
		\Bitrix\ImConnector\InfoConnectors::refreshInfoConnectors((int)$this->arParams['LINE']);

		$this->messages[] = Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_OK_CONNECT_MSGVER_1');
	}

	/**
	 * Other lines of this connector already bound to the given Yandex account (owner_id).
	 * One line == one account, so the same account must not stay bound to two lines.
	 *
	 * @return array<int,string> [lineId => human line name]
	 */
	private function findOwnerLines(string $ownerId): array
	{
		$result = [];
		if ($ownerId === '')
		{
			return $result;
		}

		$currentLine = (int)$this->arParams['LINE'];
		$hasOpenLines = Loader::includeModule('imopenlines');
		foreach ($this->connectorLineIds() as $lineId)
		{
			if ($lineId === $currentLine)
			{
				continue;
			}

			$status = Status::getInstance($this->connector, $lineId);
			if (!$status->isStatus())
			{
				continue;
			}

			$data = (array)$status->getData();
			if ((string)($data['owner_id'] ?? '') !== $ownerId)
			{
				continue;
			}

			$name = (string)($data['account_name'] ?? '');
			if ($hasOpenLines)
			{
				$config = \Bitrix\ImOpenLines\Config::getInstance()->get($lineId);
				if (!empty($config['LINE_NAME']))
				{
					$name = (string)$config['LINE_NAME'];
				}
			}

			$result[$lineId] = $name !== '' ? $name : ('#' . $lineId);
		}

		return $result;
	}

	/**
	 * Lines this connector has a status of, the current one included.
	 *
	 * Status::getInstanceAllLine() cannot be asked for them: it loads every line in one query, but only
	 * while the connector has no entry in its static cache at all - and one line is put there by every
	 * page of the connector, this one among them ({@see initialization()}), after which the same call
	 * answers with that single line. The set of lines is therefore read from the table, while the state
	 * of each stays with Status: a move frees the other lines in memory, and the table learns of it only
	 * when the request ends ({@see \Bitrix\ImConnector\Status::saveAll()}).
	 *
	 * @return int[]
	 */
	private function connectorLineIds(): array
	{
		$lineIds = array_map('intval', array_keys(Status::getInstanceAllLine($this->connector)));

		$rows = StatusConnectorsTable::getList([
			'select' => ['LINE'],
			'filter' => ['=CONNECTOR' => Connector::getConnectorRealId($this->connector)],
		]);
		while ($row = $rows->fetch())
		{
			$lineIds[] = (int)$row['LINE'];
		}

		return array_values(array_unique($lineIds));
	}

	/**
	 * Names of the lines the current user is allowed to see. The warning about an account already in use
	 * names the lines it is on, and a line the user has no rights to is not theirs to read about: the same
	 * Yandex account can be authorized by someone with rights only to their own line. What the user does
	 * need — that the account is taken and can be moved here — the warning says without naming anything,
	 * and the move itself checks the rights of every line it touches
	 * ({@see ImConnectorYandextravel::switchAccountFromOtherLines()}).
	 *
	 * @param array<int,string> $lines Lines the account is on, [lineId => human line name].
	 * @return array<int,string> The same, without the lines the user may not see.
	 */
	private function visibleLineNames(array $lines): array
	{
		// Without open lines there are no line rights to check, and the names are then the account's own
		// ({@see ImConnectorYandextravel::findOwnerLines()}) — the account the user has just authorized.
		if (!Loader::includeModule('imopenlines'))
		{
			return $lines;
		}

		return array_filter(
			$lines,
			static fn (string $name, int $lineId): bool => \Bitrix\ImOpenLines\Config::canViewLine($lineId),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Frees the account for the current line: takes the registration off every other line that holds the
	 * same owner_id, which is what the «one account = one line» check reads on both sides.
	 *
	 * Unregistered, not disconnected. The line keeps its authorization on the server, so putting the
	 * account back costs one register() ({@see restoreAccountToLines()}) — while deleteConnector() would
	 * take the line's row and its tokens along, leaving nothing to go back to but a fresh OAuth of an
	 * account the operator may no longer have at hand.
	 *
	 * @param string $ownerId Yandex account (owner_id) being moved to the current line.
	 * @return array<int,string> Lines the account was freed from, [lineId => human line name].
	 */
	private function switchAccountFromOtherLines(string $ownerId): array
	{
		$freedLines = [];
		$hasOpenLines = Loader::includeModule('imopenlines');
		foreach ($this->findOwnerLines($ownerId) as $lineId => $lineName)
		{
			$lineId = (int)$lineId;

			// Don't disconnect a line the current user is not allowed to manage (the same Yandex
			// account could be authorized by someone with rights only to their own line).
			if (
				$hasOpenLines
				&& (
					!\Bitrix\ImOpenLines\Config::canEditLine($lineId)
					|| !\Bitrix\ImOpenLines\Config::canEditConnector($lineId)
				)
			)
			{
				$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_SWITCH_FAILED');
				continue;
			}

			$output = new Output($this->connector, $lineId);
			if (!$output->unregister()->isSuccess())
			{
				$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_SWITCH_FAILED');

				// An error says nothing about the marker: the server drops it on every path unregister()
				// has, but a command that never arrived leaves it in place. So the marker is read instead
				// of assumed, and the move goes on only for a line the server itself calls unregistered.
				// An answer that could not be read is not that: marking a line unregistered while the
				// server still routes to it is the worst of the outcomes — the operator sees it
				// disconnected, and its incoming messages are refused as belonging to an inactive line.
				if ($this->serverRegistrationOf($output) !== false)
				{
					continue;
				}
			}

			$freedLines[$lineId] = $lineName;

			// The portal side of the same marker: what the duplicate check of this page reads, and what
			// shows the line as no longer served. The authorization and the account it names stay in the
			// status, which is what makes the line restorable.
			Status::getInstance($this->connector, $lineId)->setRegister(false);
			\Bitrix\ImConnector\InfoConnectors::refreshInfoConnectors($lineId);
		}

		return $freedLines;
	}

	/**
	 * What the connector-server says about the registration of the line behind the given output.
	 *
	 * Three answers, not two: a server that cannot be reached and a server that predates the flag both
	 * report nothing, and «nothing» is not «unregistered». Only an answer that names the line
	 * unregistered may move the portal status, because the portal status is what decides whether the
	 * line's incoming messages are accepted at all.
	 *
	 * @param Output $output Output of the line to ask about.
	 * @return bool|null True — still registered, false — no longer registered, null — unknown.
	 */
	private function serverRegistrationOf(Output $output): ?bool
	{
		$info = $output->infoConnect();
		if (!$info->isSuccess())
		{
			return null;
		}

		$data = (array)$info->getResult();
		if (!isset($data['registered']))
		{
			return null;
		}

		return $data['registered'] === 'Y';
	}

	/**
	 * Puts the account back on the lines a failed move freed. Their authorization was never touched, so a
	 * register() is the whole of it; the binding id it mints replaces the one in the status, which is what
	 * tells a deactivation command meant for this connection from one about the connection it replaced.
	 *
	 * The account is put back only where it is still the account of the line. A freed line keeps its
	 * authorization, so it can be authorized anew in the meantime - a pass of its own, on a different
	 * account - and a register() would then bind that other account while the status here still named the
	 * freed one: the server, this page and the duplicate check would describe different accounts. So the
	 * identity is read from the server before the line is registered again, and written from what the
	 * server reports after it, rather than from what this page remembered.
	 *
	 * @see \Bitrix\ImConnector\Provider\ImConnectorServer\Input::deactivateConnector()
	 *
	 * @param array<int,string> $freedLines Lines to register again, [lineId => human line name].
	 * @param string            $ownerId    The account they were freed from.
	 */
	private function restoreAccountToLines(array $freedLines, string $ownerId): void
	{
		foreach ($freedLines as $lineId => $lineName)
		{
			$lineId = (int)$lineId;
			$output = new Output($this->connector, $lineId);

			// The name is the operator's own text and the messages block prints it as it is, so it is
			// escaped where it enters the message.
			$line = htmlspecialcharsbx($lineName);

			$identity = $this->identityOf($output);
			if (empty($identity) || (string)($identity['owner_id'] ?? '') !== $ownerId)
			{
				// Registering this line now would bind whatever account it carries at the moment, which is
				// not the one the operator was moving. Both lines are left without the account and the
				// operator is told which one to connect by hand.
				$this->error[] = Loc::getMessage(
					'IMCONNECTOR_COMPONENT_YANDEXTRAVEL_SWITCH_NOT_RESTORED_CHANGED',
					['#LINE#' => $line],
				);

				continue;
			}

			$register = $output->register();
			if (!$register->isSuccess())
			{
				// Both lines are without the account now, and only the operator can decide which one gets
				// it: naming the line here is the least that keeps the state from being a silent one.
				$this->error[] = Loc::getMessage(
					'IMCONNECTOR_COMPONENT_YANDEXTRAVEL_SWITCH_NOT_RESTORED',
					['#LINE#' => $line],
				);

				continue;
			}

			// What the server holds now that the line is registered again; the read before the register is
			// the fallback, an unreadable answer must not empty the identity of a working connection.
			$registered = $this->identityOf($output) ?: $identity;

			$status = Status::getInstance($this->connector, $lineId);
			$data = (array)$status->getData();
			$data['owner_id'] = (string)($registered['owner_id'] ?? '');
			$data['account_name'] = (string)($registered['name'] ?? '');
			$data['binding_id'] = (string)(((array)$register->getResult())['binding_id'] ?? '');
			$status->setData($data);
			$status->setRegister(true);

			\Bitrix\ImConnector\InfoConnectors::refreshInfoConnectors($lineId);
		}
	}

	/**
	 * The account the connector-server holds for the line behind the given output.
	 *
	 * @param Output $output Output of the line to ask about.
	 * @return array Empty when the server did not answer, {owner_id, name} otherwise.
	 */
	private function identityOf(Output $output): array
	{
		$info = $output->infoConnect();
		if (!$info->isSuccess())
		{
			return [];
		}

		$data = (array)$info->getResult();
		$ownerId = (string)($data['owner_id'] ?? '');

		return $ownerId === '' ? [] : ['owner_id' => $ownerId, 'name' => (string)($data['name'] ?? '')];
	}

	/**
	 * @return mixed|void
	 * @throws LoaderException
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function executeComponent()
	{
		$this->includeComponentLang('class.php');

		if ($this->checkModules())
		{
			if (Connector::isConnector($this->connector) && $this->isConnectorAvailable())
			{
				$this->initialization();

				$this->arResult["PAGE"] = $this->request[$this->pageId];
				$this->saveForm();

				$this->constructionForm();

				if (!empty($this->error))
					$this->arResult['error'] = $this->error;

				if (!empty($this->messages))
					$this->arResult['messages'] = $this->messages;

				$this->includeComponentTemplate();
			}
			else
			{
				ShowError(Loc::getMessage("IMCONNECTOR_COMPONENT_YANDEXTRAVEL_NO_ACTIVE_CONNECTOR_MSGVER_1"));
			}
		}
	}

	private function requestOauthUrl(): void
	{
		$settings = $this->connectorOutput->getOauthUrl();
		if ($settings->isSuccess())
		{
			$oauthData = $settings->getResult();
			$this->arResult['FORM']['authorization_url'] = $oauthData['authorization_url'] ?? '';
			$this->callbackOrigin = $oauthData['callback_origin'] ?? null;
		}
		else
		{
			// Without a url there is nothing for the button to open, so the reason is shown instead of a
			// button that quietly does nothing (@see Yandextravel::getOauthUrl()).
			$this->arResult['FORM']['authorization_url'] = '';

			foreach ($settings->getErrors() as $error)
			{
				$this->error[] = $error->getCode() === 'CONNECTOR_YANDEXTRAVEL_LIFECYCLE_LOCK_BUSY'
					? Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_OAUTH_URL_BUSY')
					: Loc::getMessage('IMCONNECTOR_COMPONENT_YANDEXTRAVEL_SETTINGS_INCORRECT')
				;

				break;
			}
		}

		$this->arResult['OAUTH_TRUSTED_ORIGINS'] = !empty($this->callbackOrigin) ? [$this->callbackOrigin] : [];
	}
};
