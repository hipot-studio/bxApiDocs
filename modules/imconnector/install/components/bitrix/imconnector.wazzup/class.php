<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

use Bitrix\ImConnector\Connectors\Wazzup;
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

class ImConnectorWazzup extends \CBitrixComponent
{
	/* @TODO replace with real one */
	public const HELPDESK_CODE = '26927854';

	private ?string $callbackOrigin = null;

	private $cacheId;
	private $connector = 'wazzup';
	private $error = [];
	private $messages = [];
	/** @var Output */
	private $connectorOutput;
	/** @var Status */
	private $status;
	protected $pageId = 'page_wabt';
	private $listOptions = ['api_key', 'channel'];

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
			ShowError(Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_MODULE_NOT_INSTALLED_MSGVER_1'));
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
					if (isset($this->request[$this->connector. '_save']))
					{
						foreach ($this->listOptions as $value)
						{
							if (!empty($this->request[$value]))
							{
								$this->arResult["FORM"][$value] = $this->request[$value];
							}
						}

						if (!empty($this->arResult["FORM"]))
						{
							if (!empty($this->arResult['REGISTER_STATUS']))
							{
								$this->connectorOutput->unregister();
							}

							foreach ($this->arResult["FORM"] as $cell=>$value)
							{
								if (!empty($value))
								{
									$value = trim(htmlspecialcharsbx($value));

									$this->arResult["FORM"][$cell] = $value;
								}
							}

							if (empty($this->arResult['FORM']['channel']))
							{
								$apiKeyParam = isset($this->arResult['FORM']['api_key']) ? $this->arResult['FORM']['api_key'] : null;
								$channelList = $this->connectorOutput->getChannelsList($apiKeyParam);

								if ($channelList->isSuccess())
								{
									$this->arResult['CHANNELS'] = $channelList->getResult();
									foreach ($this->arResult['CHANNELS'] as $channelKey => $channelValue)
									{
										$this->arResult['CHANNELS'][$channelKey]['title'] = $this->getChannelTypePhrase($channelValue['transport']);
									}
									$this->messages[] = Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_SELECT_CHANNEL');
								}
								else
								{
									$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_NO_SAVE_MSGVER_1');
									$this->arResult['SAVE_STATUS'] = false;

									$this->status->setConnection(false);
									$this->arResult['CONNECTION_STATUS'] = false;
									$this->status->setRegister(false);
									$this->arResult['REGISTER_STATUS'] = false;

									$this->arResult['STATUS'] = false;
								}
							}
							else
							{
								$saved = $this->connectorOutput->saveSettings($this->arResult['FORM']);
								if ($saved->isSuccess())
								{
									$this->messages[] = Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_OK_SAVE_MSGVER_1');
									$this->arResult["SAVE_STATUS"] = true;
									$this->status->setError(false);
									$this->arResult["ERROR_STATUS"] = false;

									$apiKeyParam = isset($this->arResult['FORM']['api_key']) ? $this->arResult['FORM']['api_key'] : null;
									$channelList = $this->connectorOutput->getChannelsList($apiKeyParam);

									if ($channelList->isSuccess())
									{
										foreach ($channelList->getResult() as $channel)
										{
											if ($channel['channelId'] == $this->arResult['FORM']['channel'])
											{
												// Prepare data to save in status
												$dataToSave = [
													'channelName' => $channel['plainId'],
													'channelId' => $channel['channelId'],
													'channelType' => $channel['transport'],
												];

												if (!empty($this->arResult['FORM']['api_key']))
												{
													$dataToSave['apiKey'] = Wazzup::hideApiKey($this->arResult['FORM']['api_key']);
													$this->arResult['placeholder']['api_key'] = Wazzup::hideApiKey($this->arResult['FORM']['api_key']);
												}

												$this->status->setData($dataToSave);
												\Bitrix\ImConnector\InfoConnectors::refreshInfoConnectors((int)$this->arParams['LINE']);

												$this->arResult['placeholder']['channel'] = $this->getChannelTypePhrase($dataToSave['channelType']) . ': ' . $channel['name'];
												break;
											}
										}
									}
								}
								else
								{
									$this->error[] = Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_NO_SAVE_MSGVER_1");
									$this->arResult["SAVE_STATUS"] = false;

									$this->status->setConnection(false);
									$this->arResult["CONNECTION_STATUS"] = false;
									$this->status->setRegister(false);
									$this->arResult["REGISTER_STATUS"] = false;

									$this->arResult["STATUS"] = false;
								}
							}
						}
						else
						{
							$this->error[] = Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_NO_DATA_SAVE");
						}

						$this->cleanCache();
					}

					if (($this->request[$this->connector. '_save'] && $this->arResult["SAVE_STATUS"]) || $this->request[$this->connector. '_tested'])
					{
						$testConnect = $this->connectorOutput->testConnect();

						if ($testConnect->isSuccess())
						{
							$this->messages[] = Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_OK_CONNECT_MSGVER_1");

							$this->status->setConnection(true);
							$this->arResult["CONNECTION_STATUS"] = true;

							$this->status->setRegister(true);
							$this->arResult["REGISTER_STATUS"] = true;
							$this->arResult["STATUS"] = true;

							$this->status->setError(false);
							$this->arResult["ERROR_STATUS"] = false;

							$this->arResult["PAGE"] = '';
						}
						else
						{
							foreach ($testConnect->getErrors() as $error)
							{
								if ($error->getCode() == 'CONNECTOR_SETTINGS_INCORRECT')
								{
									$this->error[] = Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_SETTINGS_INCORRECT");
								}
								elseif ($error->getCode() == 'CONNECTOR_NO_ANSWER_CLIENT')
								{
									$this->error[] = Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_NO_ANSWER_CLIENT_MSGVER_1");
								}
								else
								{
									$this->error[] = Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_NO_CONNECT_MSGVER_1");
								}

								break;
							}

							$this->status->setConnection(false);
							$this->arResult["CONNECTION_STATUS"] = false;

							$this->status->setRegister(false);
							$this->arResult["REGISTER_STATUS"] = false;
							$this->arResult["STATUS"] = false;
						}

						//Reset cache
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

						//Reset cache
						$this->cleanCache();
					}
				}
			}
			else
			{
				$this->error[] = Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_SESSION_HAS_EXPIRED_MSGVER_1");
			}
		}
	}

	public function constructionForm()
	{
		global $APPLICATION;

		$authInfo = $this->connectorOutput->getAuthorizationInformation();
		$hasApiKey = false;
		$hasOAuthTokens = false;

		if ($authInfo->isSuccess())
		{
			$authData = $authInfo->getResult();

			if (!empty($authData['SETTINGS']['api_key']))
			{
				$hasApiKey = true;
				$this->arResult['HAS_API_KEY'] = true;
			}

			if (!empty($authData['SETTINGS']['access_token']) || !empty($authData['SETTINGS']['refresh_token']))
			{
				$hasOAuthTokens = true;
				$this->arResult['HAS_OAUTH_TOKENS'] = true;
			}
		}

		if (!$hasOAuthTokens && !$hasApiKey)
		{
			$this->requestOauthUrl();
		}

		if ($hasOAuthTokens)
		{
			$channelsList = $this->connectorOutput->getChannelsList();
			if ($channelsList->isSuccess())
			{
				$this->arResult['CHANNELS'] = $channelsList->getResult();
				foreach ($this->arResult['CHANNELS'] as $channelKey => $channelValue)
				{
					$this->arResult['CHANNELS'][$channelKey]['title'] = $this->getChannelTypePhrase($channelValue['transport']);
				}

				if (empty($authData['SETTINGS']['channel']))
				{
					$activeChannels = array_filter($this->arResult['CHANNELS'], static function($channel) {
						return $channel['state'] === 'active';
					});

					if (empty($activeChannels))
					{
						$this->arResult['NO_AVAILABLE_CHANNELS'] = true;
					}
					elseif (count($activeChannels) === 1)
					{
						$singleChannel = reset($activeChannels);

						$this->arResult['FORM']['channel'] = $singleChannel['channelId'];

						$saved = $this->connectorOutput->saveSettings($this->arResult['FORM']);
						if (!$saved->isSuccess())
						{
							foreach ($saved->getErrors() as $error)
							{
								$this->error[] = $error->getMessage();
							}
							return;
						}

						$this->messages[] = Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_OK_SAVE_MSGVER_1');

						$dataToSave = [
							'channel' => $singleChannel['channelId'],
							'channelName' => $singleChannel['plainId'],
							'channelType' => $singleChannel['transport'],
						];

						$this->status->setData($dataToSave);
						\Bitrix\ImConnector\InfoConnectors::refreshInfoConnectors((int)$this->arParams['LINE']);

						$this->arResult['placeholder']['channel'] = $this->getChannelTypePhrase($singleChannel['transport']) . ': ' . $singleChannel['name'];

						$testConnect = $this->connectorOutput->testConnect();
						if ($testConnect->isSuccess())
						{
							$this->messages[] = Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_OK_CONNECT_MSGVER_1");

							$this->status->setConnection(true);
							$this->arResult["CONNECTION_STATUS"] = true;

							$this->status->setRegister(true);
							$this->arResult["REGISTER_STATUS"] = true;
							$this->arResult["STATUS"] = true;

							$this->status->setError(false);
							$this->arResult["ERROR_STATUS"] = false;

							$this->arResult["PAGE"] = '';
						}
						else
						{
							foreach ($testConnect->getErrors() as $error)
							{
								$this->error[] = $error->getMessage();
							}
						}

						$this->cleanCache();
					}
					else
					{
						$this->messages[] = Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_SELECT_CHANNEL');
					}
				}
			}
			else
			{
				foreach ($channelsList->getErrors() as $error)
				{
					if ($error->getCode() === 'OAUTH_TOKEN_UNKNOWN_OR_REVOKED')
					{
						$this->error[] = Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_OAUTH_TOKEN_REVOKED');
						$this->requestOauthUrl();
					}
					elseif (!empty($error->getMessage()))
					{
						$this->error[] = $error->getMessage();
					}
				}
			}
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
			if (!empty($this->arResult["PAGE"]))
			{
				$settings = $this->connectorOutput->getAuthorizationInformation();

				$result = $settings->getResult();

				foreach ($this->listOptions as $value)
				{
					if (empty($this->arResult['FORM'][$value]))
					{
						if (!empty($result['SETTINGS'][$value]))
						{
							$this->arResult['SAVE_STATUS'] = true;
							if ($result['SETTINGS'][$value] === '#HIDDEN#')
							{
								$statusData = $this->status->getData();
								if ($value === 'channel')
								{
									if (!empty($statusData['channelType']) && !empty($statusData['channelName']))
									{
										$this->arResult['placeholder'][$value] = $this->getChannelTypePhrase($statusData['channelType']) . ": " . $statusData['channelName'];
									}
								}
								elseif ($value === 'api_key')
								{
									$this->arResult['placeholder'][$value] = Wazzup::hideApiKey($statusData['apiKey']);
								}
								else
								{
									$this->arResult['placeholder'][$value] = true;
								}
							}
							else
							{
								$this->arResult['FORM'][$value] = $result['SETTINGS'][$value];
							}
						}
					}
				}
			}

			if (!empty($result['URL_WEBHOOK']))
			{
				$this->arResult["URL_WEBHOOK"] = $result['URL_WEBHOOK'];
			}

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
						$infoConnectData = $infoConnect->getData();

						$this->arResult["INFO_CONNECTION"] = [
							'URL' => $infoConnectData["url"] ?? '',
							'NAME' => $infoConnectData["name"]
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
			if (Connector::isConnector($this->connector))
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
				ShowError(Loc::getMessage("IMCONNECTOR_COMPONENT_WAZZUP_NO_ACTIVE_CONNECTOR_MSGVER_1"));
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
			$this->arResult['FORM']['authorization_url'] = '';
		}

		$this->arResult['OAUTH_TRUSTED_ORIGINS'] = !empty($this->callbackOrigin) ? [$this->callbackOrigin] : [];
	}

	private function getChannelTypePhrase($channelType): string
	{
		return match ($channelType)
		{
			'tgapi' => Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_CHANNEL_TYPE_TELEGRAM'),
			'telegram' => Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_CHANNEL_TYPE_TELEGRAM_BOT'),
			'whatsapp' => Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_CHANNEL_TYPE_WHATSAPP'),
			'wapi' => Loc::getMessage('IMCONNECTOR_COMPONENT_WAZZUP_CHANNEL_TYPE_WHATSAPP_WABA'),
			default => $channelType,
		};
	}
};
