<?php

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxConnectDTO;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Enum\MailboxConnectionRequestStatus;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\Helper\Mailbox\MailboxSettingsConfig;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mail;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\Web\Json;
use Bitrix\UI\Buttons\Button;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\Icon;
use Bitrix\UI\Buttons\JsCode;
use Bitrix\UI\Buttons\Tag;
use Bitrix\UI\Toolbar\Facade\Toolbar;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

Loc::loadMessages(__DIR__ . '/../mail.client/class.php');

\Bitrix\Main\Loader::includeModule('mail');

class CMailClientConfigComponent extends CBitrixComponent implements Main\Engine\Contract\Controllerable, Main\Errorable
{
	private const NEGATIVE_ANSWER = 'N';
	private const POSITIVE_ANSWER = 'Y';
	private const DEFAULT_SEND_LIMIT = 250;
	private const ACCESS_CODE_USER_PREFIX = 'U';

	private Main\ErrorCollection $errorCollection;

	public function configureActions()
	{
		$this->errorCollection = new Main\ErrorCollection();

		return [];
	}

	public function executeComponent()
	{
		global $USER, $APPLICATION;

		if (!is_object($USER) || !$USER->isAuthorized())
		{
			$APPLICATION->authForm('');

			return;
		}

		$result = \Bitrix\Main\SiteTable::getList();
		while (($site = $result->fetch()) !== false)
		{
			\Bitrix\Mail\Internals\MailServiceInstaller::checkInstallComplete($site["LID"]);
		}

		switch ($this->arParams['VARIABLES']['act'])
		{
			case 'new':
				$this->editAction(true);

				break;
			case 'edit':
				$this->editAction(false);

				break;
			default:
				$this->defaultAction();
		}
	}

	protected function defaultAction()
	{
		global $USER, $APPLICATION;

		$APPLICATION->setTitle(Loc::getMessage('MAIL_CLIENT_CONFIG_TITLE'));

		$this->arResult['MAX_ALLOWED_CONNECTED_MAILBOXES'] = LicenseManager::getUserMailboxesLimit();
		$this->arResult['CAN_CONNECT_NEW_MAILBOX'] = MailboxConnector::checkConnectionLimits((int)$USER->getId());
		$this->arParams['DEFAULT_SEND_LIMIT'] = self::DEFAULT_SEND_LIMIT;

		$isMainMailPage = $this->arParams['VARIABLES']['IS_MAIN_MAIL_PAGE'] ?? false;
		$this->prepareToolbarButton($isMainMailPage);

		$this->includeComponentTemplate();
	}

	protected function editAction($new = true)
	{
		if ($new && !Mail\Helper\MailboxAccess::hasCurrentUserAccessToAddMailbox())
		{
			$this->includeComponentTemplate('access_denied');

			return;
		}

		global $APPLICATION, $USER;

		$APPLICATION->setTitle(Loc::getMessage($new ? 'MAIL_CLIENT_CONFIG_TITLE' : 'MAIL_CLIENT_CONFIG_EDIT_TITLE'));

		$this->setIsSmtpAvailable();

		if ($new)
		{
			$userIdToCheck = $USER->getId();

			if (!MailboxConnector::checkConnectionLimits((int)$userIdToCheck))
			{
				showError(Loc::getMessage('MAIL_CLIENT_DENIED'));

				return;
			}

			$serviceId = (int)$_REQUEST['id'];
		}
		else
		{
			$mailbox = Mail\MailboxTable::query()
				->setSelect(['*'])
				->where('ID', (int)$_REQUEST['id'])
				->whereIn('ACTIVE', [
					MailboxStatus::Active->value,
					MailboxStatus::Pending->value,
					MailboxStatus::Canceled->value,
				])
				->where('SERVER_TYPE', 'imap')
				->fetch()
			;

			if (empty($mailbox))
			{
				showError(Loc::getMessage('MAIL_CLIENT_ELEMENT_NOT_FOUND'));

				return;
			}

			$canManage = Mail\Helper\MailboxAccess::hasCurrentUserAccessToEditMailbox($mailbox['ID']);
			if (!$canManage)
			{
				showError(Loc::getMessage('MAIL_CLIENT_DENIED'));

				return;
			}

			$isPasswordlessMailbox = in_array($mailbox['ACTIVE'], [
				MailboxStatus::Pending->value,
				MailboxStatus::Canceled->value,
			], true);
			if ($isPasswordlessMailbox && !MailAccess::hasCurrentUserAccessToMassConnect())
			{
				showError(Loc::getMessage('MAIL_CLIENT_DENIED'));

				return;
			}

			foreach ([$mailbox['EMAIL'], $mailbox['NAME'], $mailbox['LOGIN']] as $item)
			{
				$address = new \Bitrix\Main\Mail\Address($item);
				if ($address->validate())
				{
					$mailbox['EMAIL'] = $address->getEmail();

					break;
				}
			}

			if ($mailbox)
			{
				if ($this->arParams['IS_SMTP_AVAILABLE'])
				{
					$sender = $this->getMailboxSender((int)$mailbox['ID'], $mailbox['EMAIL'], (int)$mailbox['USER_ID']);

					if ($sender)
					{
						$mailbox['__smtp'] = $sender['OPTIONS']['smtp'];
						$mailbox['USERNAME'] = $sender['NAME'];
						$mailbox['USE_SENDER_NAME'] = UserSenderDataProvider::shouldUseCustomSenderName($sender) ? 'Y' : 'N';
					}
					elseif (!empty($mailbox['OPTIONS']['passwordless_smtp']))
					{
						$passwordlessSmtp = $mailbox['OPTIONS']['passwordless_smtp'];
						$mailbox['__smtp'] = $passwordlessSmtp;
						if (!empty($passwordlessSmtp['senderName']))
						{
							$mailbox['USERNAME'] = $passwordlessSmtp['senderName'];
						}
						if (isset($passwordlessSmtp['useSenderName']))
						{
							$mailbox['USE_SENDER_NAME'] = $passwordlessSmtp['useSenderName'] ? 'Y' : 'N';
						}
					}
				}
				else
				{
					$mailbox['USE_SENDER_NAME'] = $this->shouldUseCustomSenderName($mailbox) ? 'Y' : 'N';
				}
			}

			if (in_array('crm_connect', (array)$mailbox['OPTIONS']['flags']))
			{
				$mailbox['__crm'] = true;
			}

			$this->arParams['PASSWORD_PLACEHOLDER'] = '000000000000';

			$this->arParams['MAILBOX'] = $mailbox;

			$serviceId = $mailbox['SERVICE_ID'];
		}

		$this->arParams['HAS_NO_ACCESS_TO_SHARE_MAILBOX'] = !$new
			&& !MailboxAccess::hasCurrentUserAccessToEditMailboxAccess(
				$mailbox['ID'] ?? 0,
				$mailbox['USER_ID'] ?? 0,
			)
		;

		$this->arParams['IS_CALENDAR_AVAILABLE'] = \Bitrix\Main\Loader::includeModule('calendar');
		$this->arParams['IS_ICAL_CHECK'] = $mailbox['OPTIONS']['ical_access'] === self::POSITIVE_ANSWER;


		$res = Mail\MailServicesTable::getList([
			'filter' => [
				'=ID' => $serviceId,
				'=SITE_ID' => SITE_ID,
			],
		]);

		$this->arParams['SERVICE'] = [];
		if ($service = $res->fetch())
		{
			$this->arParams['SERVICE'] = [
				'active' => $service['ACTIVE'],
				'id' => $service['ID'],
				'type' => $service['SERVICE_TYPE'],
				'name' => $service['NAME'],
				'link' => $service['LINK'],
				'icon' => Mail\MailServicesTable::getIconSrc($service['NAME'], $service['ICON']),
				'server' => $service['SERVER'],
				'port' => $service['PORT'],
				'encryption' => $service['ENCRYPTION'],
				'upload_outgoing' => $service['UPLOAD_OUTGOING'],
			];
			$this->arParams['SERVICE'] = self::prepareMailServices([$this->arParams['SERVICE']])[0];

			$serviceSmtp = [];
			if(!empty($service['SMTP_SERVER']))
			{
				$serviceSmtp['server'] = $service['SMTP_SERVER'];
			}
			if(!empty($service['SMTP_PORT']))
			{
				$serviceSmtp['port'] = $service['SMTP_PORT'];
			}
			$serviceSmtp['login'] = ($service['SMTP_LOGIN_AS_IMAP'] === 'Y');
			$serviceSmtp['password'] = ($service['SMTP_PASSWORD_AS_IMAP'] === 'Y');
			$this->arParams['SERVICE']['smtp'] = $serviceSmtp;

		}
		elseif ($new)
		{
			showError(Loc::getMessage('MAIL_CLIENT_ELEMENT_NOT_FOUND'));

			return;
		}

		if (!$new)
		{
			$this->arParams['SERVICE']['oauth'] = Mail\Helper\OAuth::getInstanceByMeta($mailbox['PASSWORD']);
			$this->arParams['SERVICE']['oauth_user'] = Mail\Helper\OAuth::getUserDataByMeta($mailbox['PASSWORD']);
			$this->arParams['SERVICE']['oauth_user']['email'] = $mailbox['EMAIL'];
		}

		if (empty($this->arParams['SERVICE']['oauth']))
		{
			if ($new || empty($mailbox['PASSWORD']))
			{
				$this->arParams['SERVICE']['oauth'] = Mail\MailServicesTable::getOAuthHelper($service);
			}
		}
		$this->arParams['SERVICE']['oauth_smtp_enabled'] = !empty($this->arParams['SERVICE']['oauth'])
			&& MailboxConnector::isOauthSmtpEnabled($this->arParams['SERVICE']['name'] ?? '');

		if (!empty($this->arParams['SERVICE']['oauth']) && empty($this->arParams['SERVICE']['oauth_smtp_enabled']))
		{
			$this->arParams['SERVICE']['smtp']['password'] = false;
		}

		$ownerId = $new ? $USER->getId() : $mailbox['USER_ID'];
		$access = [
			'user' => [
				sprintf('U%u', $ownerId) => $ownerId,
			],
			'department' => [],
		];

		if (!$new)
		{
			$res = Mail\Internals\MailboxAccessTable::getList([
				'filter' => [
					'=MAILBOX_ID' => $mailbox['ID'],
					'TASK_ID' => 0,
				],
			]);

			while ($item = $res->fetch())
			{
				if (preg_match('/^(U|DR|D)(\d+)$/', $item['ACCESS_CODE'], $matches))
				{
					if ($matches[1] == 'U')
					{
						$access['user'][$item['ACCESS_CODE']] = $matches[2];
					}
					elseif ($matches[1] == 'DR')
					{
						$access['department'][$item['ACCESS_CODE']] = [
							'id' => $item['ACCESS_CODE'],
							'entityId' => $matches[2],
						];
					}
					else
					{
						$access['department'][$item['ACCESS_CODE']] = [
							'id' => $item['ACCESS_CODE'],
							'entityId' => $matches[2] . ':F',
						];
					}
				}
			}
		}

		$res = Main\UserTable::getList([
			'filter' => [
				'@ID' => array_values($access['user']),
			],
		]);

		while ($item = $res->fetch())
		{
			$id = sprintf('U%u', $item['ID']);
			$access['user'][$id] = [
				'id'       => $id,
				'entityId' => $item['ID'],
				'name'     => \CUser::formatName(\CSite::getNameFormat(), $item, true),
				'avatar'   => '',
				'desc'     => $item['WORK_POSITION'] ?: $item['PERSONAL_PROFESSION'] ?: '&nbsp;',
			];
		}

		$this->arParams['ACCESS_LIST'] = array_map(
			function ($list)
			{
				return array_filter($list, 'is_array');
			},
			$access,
		);

		if (\Bitrix\Main\Loader::includeModule('socialnetwork'))
		{
			$this->arParams['COMPANY_STRUCTURE'] = \CSocNetLogDestination::getStucture();
		}

		$this->arParams['CRM_AVAILABLE'] = false;
		$this->arParams['HAS_ACCESS_TO_VIEW_CRM'] = MailboxAccess::hasCurrentUserAccessToViewMailboxIntegrationCrm();
		$this->arParams['HAS_ACCESS_TO_EDIT_CRM'] = MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm();

		if ($this->arParams['HAS_ACCESS_TO_VIEW_CRM'])
		{
			$this->arParams['CRM_AVAILABLE'] = Feature::isCrmAvailable();

			if ($this->arParams['CRM_AVAILABLE'])
			{
				$this->arParams['LEAD_SOURCE_LIST'] = MailboxSettingsConfig::getCrmSourcesMap();
				$defaultSettings = MailboxSettingsConfig::getDefaultSettings(
					$this->arParams['LEAD_SOURCE_LIST'],
				);
				$this->arParams['NEW_ENTITY_LIST'] = MailboxSettingsConfig::getCrmEntitiesMap();
				$this->arParams['DEFAULT_NEW_ENTITY_IN'] = $defaultSettings['crmIncomingEntity'];
				$this->arParams['DEFAULT_NEW_ENTITY_OUT'] = $defaultSettings['crmOutgoingEntity'];
				$this->arParams['DEFAULT_LEAD_SOURCE'] = $defaultSettings['crmSource'];

				if (!$new)
				{
					$options = $mailbox['OPTIONS'];

					if (!array_key_exists('flags', $options) || !is_array($options['flags']))
					{
						$options['flags'] = [];
					}

					if ($mailbox['__crm'])
					{
						// backward compatibility
						if (!array_intersect(['crm_deny_new_lead', 'crm_deny_entity_in', 'crm_deny_entity_out'], $options['flags']))
						{
							$this->arParams['DEFAULT_NEW_ENTITY_IN'] = \CCrmOwnerType::LeadName;
							$this->arParams['DEFAULT_NEW_ENTITY_OUT'] = \CCrmOwnerType::LeadName;
						}
					}

					if (!empty($options['crm_new_entity_in']) && array_key_exists($options['crm_new_entity_in'], $this->arParams['NEW_ENTITY_LIST']))
					{
						$this->arParams['DEFAULT_NEW_ENTITY_IN'] = $options['crm_new_entity_in'];
					}
					if (!empty($options['crm_new_entity_out']) && array_key_exists($options['crm_new_entity_out'], $this->arParams['NEW_ENTITY_LIST']))
					{
						$this->arParams['DEFAULT_NEW_ENTITY_OUT'] = $options['crm_new_entity_out'];
					}

					if (!empty($options['crm_lead_source']) && array_key_exists($options['crm_lead_source'], $this->arParams['LEAD_SOURCE_LIST']))
					{
						$this->arParams['DEFAULT_LEAD_SOURCE'] = $options['crm_lead_source'];
					}

					if (!empty($options['crm_lead_resp']))
					{
						$this->arParams['CRM_QUEUE'] = \Bitrix\Main\UserTable::getList([
							'filter' => [
								'ID' => $options['crm_lead_resp'],
							],
						])->fetchAll();

						$order = array_flip(array_values(array_unique($options['crm_lead_resp'])));
						usort($this->arParams['CRM_QUEUE'], function ($a, $b) use (&$order)
						{
							return isset($order[$a['ID']], $order[$b['ID']]) ? $order[$a['ID']]-$order[$b['ID']] : 0;
						});
					}

					$this->arParams['NEW_LEAD_FOR'] = is_array($options['crm_new_lead_for']) ? $options['crm_new_lead_for'] : [];
				}

				if (empty($this->arParams['CRM_QUEUE']))
				{
					$this->arParams['CRM_QUEUE'] = \Bitrix\Main\UserTable::getList([
						'filter' => [
							'ID' => $new ? $USER->getId() : $mailbox['USER_ID'],
						],
					])->fetchAll();
				}
			}
		}
		$this->arResult['FORBIDDEN_TO_SHARE_MAILBOX'] = false;
		$sharedMailboxesLimit = LicenseManager::getSharedMailboxesLimit();
		if ($sharedMailboxesLimit >= 0)
		{
			$sharedMailboxesIds = Mail\Helper\Mailbox\SharedMailboxesManager::getSharedMailboxesIds();
			if (count($sharedMailboxesIds) >= $sharedMailboxesLimit
				&& (!empty($mailbox) ? (!in_array((int)$mailbox['ID'], $sharedMailboxesIds, true)) : true))
			{
				$this->arResult['FORBIDDEN_TO_SHARE_MAILBOX'] = true;
			}
		}
		if (!empty($mailbox))
		{
			$mailboxSyncManager = new Mail\Helper\Mailbox\MailboxSyncManager($mailbox['USER_ID']);
			$this->arResult['LAST_MAIL_CHECK_DATE'] = $mailboxSyncManager->getLastMailboxSyncTime($mailbox['ID']);
			$this->arResult['LAST_MAIL_CHECK_STATUS'] = $mailboxSyncManager->getCachedConnectionStatus($mailbox['ID']);
		}

		$this->arResult['MICROSOFT_SERVICE_NAMES'] = $this->getMicrosoftServiceNames();

		$this->arResult['IS_SMTP_SENDER_ADDED'] = ($_REQUEST['smtp'] ?? '') === 'Y';
		$this->arResult['LOCK_SMTP'] = $this->isSmtpSwitcherDisabled();

		$this->arParams['DEFAULT_SEND_LIMIT'] = self::DEFAULT_SEND_LIMIT;

		$this->arParams['SERVICE']['IS_SMTP_SWITCHER_CHECKED'] = $this->arResult['LOCK_SMTP'] === true || $this->isSmtpSwitcherChecked();
		$this->arParams['SENDER_NAME'] = $this->getSenderName($mailbox['USERNAME'] ?? '', $mailbox['USER_ID'] ?? null);
		$this->arParams['USE_SENDER_NAME'] = $mailbox['USE_SENDER_NAME'] === 'Y';

		$this->arParams['OWNER_ACCESS_CODE']
			= !empty($mailbox['USER_ID'])
				? self::ACCESS_CODE_USER_PREFIX . (int)$mailbox['USER_ID']
				: ''
		;

		$this->includeComponentTemplate('edit');
	}

	public function checkAvailabilityEMailAction($serviceId,$email,$oauthUid)
	{
		$service = Mail\MailServicesTable::getList([
			'filter' => [
				'=ID'          => $serviceId,
				'=ACTIVE'       => 'Y',
				'=SERVICE_TYPE' => 'imap',
			],
		])->fetch();

		if (!empty($service))
		{
			$mailbox = [
				'USE_TLS' => $service['ENCRYPTION'],
				'LOGIN' => $email,
				'SERVER' => $service['SERVER'],
				'PORT' => $service['PORT'],
			];

			if ($oauthHelper = Mail\MailServicesTable::getOAuthHelper($service))
			{
				$oauthHelper->getStoredToken($oauthUid);
				$mailbox['PASSWORD'] = $oauthHelper->buildMeta();

				if(\Bitrix\Mail\Helper::getImapUnseen($mailbox) !== false)
				{
					return true;
				}
			}
		}

		return false;
	}

	public function saveAction($fields)
	{
		if (!empty($fields['site_id']))
		{
			$currentSite = \CSite::getById($fields['site_id'])->fetch();
		}

		if (empty($currentSite))
		{
			$this->error(Loc::getMessage('MAIL_CLIENT_FORM_ERROR'));

			return;
		}

		$mailboxId = (int)($fields['mailbox_id'] ?? 0);

		if (!$mailboxId)
		{
			$connectionRequestId = (int)($fields['mailbox_connection_request_id'] ?? 0);

			$mailboxConnector = new MailboxConnector();
			if ($connectionRequestId > 0)
			{
				$connectionRequestService = new Mail\Helper\Mailbox\MailboxConnectionRequestService();

				if (!$connectionRequestService->isResponsibleAdmin())
				{
					$this->error(Loc::getMessage('MAIL_CLIENT_DENIED'));

					return;
				}

				$request = $connectionRequestService->getRequestById($connectionRequestId);
				if ($request === null)
				{
					$this->error(Loc::getMessage('MAIL_CLIENT_FORM_ERROR'));

					return;
				}

				if ($request['STATUS'] !== MailboxConnectionRequestStatus::Pending->value)
				{
					$this->error(Loc::getMessage('MAIL_CLIENT_CONFIG_CONNECTION_REQUEST_NOT_PENDING'));

					return;
				}

				$userIdToConnectNewMailbox = (int)$request['REQUESTER_ID'];

				if (!MailAccess::hasCurrentUserAccessToConnectMailboxToUser($userIdToConnectNewMailbox))
				{
					$this->error(Loc::getMessage('MAIL_CLIENT_DENIED'));

					return;
				}
			}
			else
			{
				$currentUserId = (int)CurrentUser::get()->getId();

				if (!$mailboxConnector->checkConnectMailbox())
				{
					$this->error(Loc::getMessage('MAIL_CLIENT_DENIED'));

					return;
				}

				$userIdToConnectNewMailbox = $currentUserId;
			}

			$mailboxConnectDTO = MailboxConnectDTO::createFromFormFieldsForConnect($fields, $currentSite, $userIdToConnectNewMailbox);

			$result = $mailboxConnector->connectMailboxWithCustomCrm($mailboxConnectDTO);
			if (!$mailboxConnector->getSuccess())
			{
				$this->error($mailboxConnector->getErrors()[0]);

				return;
			}

			$createdMailboxId = (int)($result['id'] ?? 0);
			$connectionRequestCompleted = false;

			if ($createdMailboxId > 0 && $connectionRequestId > 0)
			{
				$connectionRequestService ??= new Mail\Helper\Mailbox\MailboxConnectionRequestService();
				$completeResult = $connectionRequestService->completeRequest(
					$connectionRequestId,
					$createdMailboxId,
				);

				if ($completeResult->isSuccess())
				{
					$pendingCount = $completeResult->getData()['pendingCount'] ?? null;
					$connectionRequestCompleted = true;
				}
			}

			return [
				'id' => $createdMailboxId,
				'senderName' => $result['senderName'] ?? null,
				'connectionRequestCompleted' => $connectionRequestCompleted,
				'pendingCount' => $pendingCount ?? null,
			];
		}

		$dto = MailboxConnectDTO::createFromFormFields($fields);

		$existingMailbox = Mail\MailboxTable::getById($mailboxId)->fetch();
		if (
			$existingMailbox
			&& in_array($existingMailbox['ACTIVE'], [
				MailboxStatus::Pending->value,
				MailboxStatus::Canceled->value,
			], true)
		)
		{
			if (!MailAccess::hasCurrentUserAccessToMassConnect())
			{
				$this->error(Loc::getMessage('MAIL_CLIENT_DENIED'));

				return;
			}

			$dto->skipConnectionValidation = true;
		}

		$mailboxConnector = new MailboxConnector();
		$result = $mailboxConnector->updateMailbox($mailboxId, $dto);

		if (!$mailboxConnector->getSuccess())
		{
			$this->error($mailboxConnector->getErrors()[0]);

			return;
		}

		return [
			'id' => $result['id'] ?? 0,
			'senderName' => $result['senderName'] ?? null,
		];
	}

	/**
	 * @deprecated Use \Bitrix\Mail\Controller\MailboxConnecting::deleteMailboxAction
	 */
	public function deleteAction($id)
	{
		global $USER;

		$mailbox = Mail\MailboxTable::getList([
			'filter' => [
				'=ID' => $id,
				'=ACTIVE' => 'Y',
				'=SERVER_TYPE' => 'imap',
			],
		])->fetch();

		if (empty($mailbox))
		{
			$this->error(Loc::getMessage('MAIL_CLIENT_ELEMENT_NOT_FOUND'));

			return;
		}

		$canManage = Mail\Helper\MailboxAccess::hasCurrentUserAccessToEditMailbox($mailbox['ID']);
		if (!$canManage)
		{
			$this->error(Loc::getMessage('MAIL_CLIENT_DENIED'));

			return;
		}

		\CMailbox::update($mailbox['ID'], ['ACTIVE' => 'N']);
		self::deleteMailboxSender((int)$mailbox['ID'], $mailbox['EMAIL']);

		\CUserCounter::clear($USER->getId(), 'mail_unseen', $mailbox['LID']);
		$mailboxSyncManager = new \Bitrix\Mail\Helper\Mailbox\MailboxSyncManager($mailbox['USER_ID']);
		$mailboxSyncManager->deleteSyncData($mailbox['ID']);

		\CAgent::addAgent(sprintf('Bitrix\Mail\Helper::deleteMailboxAgent(%u);', $mailbox['ID']), 'mail', 'N', 60);
	}

	protected function error($error, $isOAuth = false, $isSender = false)
	{
		if ($error instanceof Main\ErrorCollection)
		{
			$messages = [];
			$details  = [];

			foreach ($error as $item)
			{
				${$item->getCode() < 0 ? 'details' : 'messages'}[] = $item;
			}

			if (count($messages) == 1 && reset($messages)->getCode() == Mail\Imap::ERR_AUTH)
			{
				$authError = Loc::getMessage('MAIL_CLIENT_CONFIG_IMAP_AUTH_ERR_EXT');
				if  ($isOAuth && Loc::getMessage('MAIL_CLIENT_CONFIG_IMAP_AUTH_ERR_OAUTH'))
				{
					$authError = Loc::getMessage('MAIL_CLIENT_CONFIG_IMAP_AUTH_ERR_OAUTH');
				}
				if  ($isOAuth && $isSender && Loc::getMessage('MAIL_CLIENT_CONFIG_IMAP_AUTH_ERR_OAUTH_SMTP'))
				{
					$authError = Loc::getMessage('MAIL_CLIENT_CONFIG_IMAP_AUTH_ERR_OAUTH_SMTP');
				}

				$messages = [
					new Main\Error($authError, Mail\Imap::ERR_AUTH),
				];

				$moreDetailsSection = false;
			}
			else
			{
				$moreDetailsSection = true;
			}

			$reduce = function($error)
			{
				return $error->getMessage();
			};

			if($moreDetailsSection)
			{
				$this->errorCollection[] = new Main\Error(
					implode(': ', array_map($reduce, $messages)),
					0,
					implode(': ', array_map($reduce, $details)),
				);
			}
			else
			{
				$this->errorCollection[] = new Main\Error(
					implode(': ', array_map($reduce, $messages)),
					0,
				);
			}

		}
		else
		{
			$this->errorCollection[] = new Main\Error($error);
		}
	}

	/**
	 * Getting array of errors.
	 * @return Error[]
	 */
	final public function getErrors(): array
	{
		return $this->errorCollection->toArray();
	}

	/**
	 * Getting once error with the necessary code.
	 *
	 * @param string $code Code of error.
	 * @return Main\Error
	 */
	final public function getErrorByCode($code): Main\Error
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	private function setIsSmtpAvailable()
	{
		$defaultMailConfiguration = Configuration::getValue("smtp");
		$this->arParams['IS_SMTP_AVAILABLE'] = Main\ModuleManager::isModuleInstalled('bitrix24')
			|| $defaultMailConfiguration['enabled'];
	}

	/**
	 * Prepares mail services and their names for the mail providers showcase and connected mailbox settings page
	 *
	 * @param array|null $mailboxes
	 * @return array
	 */
	private static function prepareMailServices(?array $mailboxes = null): array
	{
		$mailboxes ??= Mailbox::getServices();

		foreach ($mailboxes as &$mailbox)
		{
			$mailbox['serviceName'] = match ($mailbox['name']) {
				'aol' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_AOL'),
				'yahoo' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_YAHOO'),
				'icloud' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_ICLOUD'),
				'gmail' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_GMAIL'),
				'yandex' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_YANDEX'),
				'outlook.com' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_OUTLOOK'),
				'exchange', 'exchangeOnline' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_EXCHANGE'),
				'mail.ru' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_MAILRU'),
				'office365' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_OFFICE365'),
				'other' => Loc::getMessage('MAIL_MAILBOX_SERVICE_NAME_IMAP_MSGVER_1'),
				default => ucfirst($mailbox['name']),
			};
		}

		return $mailboxes;
	}

	private function isSmtpSwitcherChecked(): bool
	{
		$excludedServices = $this->getMicrosoftServiceNames();
		$mailbox = $this->arParams['MAILBOX'] ?? [];
		$service = $this->arParams['SERVICE'] ?? [];

		if (!empty($mailbox['__smtp']))
		{
			return true;
		}

		if (!empty($mailbox))
		{
			return false;
		}

		return !in_array($service['name'] ?? null, $excludedServices, true);
	}

	private function isSmtpSwitcherDisabled(): bool
	{

		if (!empty($this->arParams['MAILBOX']) && !$this->isSmtpSwitcherChecked())
		{
			return false;
		}

		if ($this->arResult['IS_SMTP_SENDER_ADDED'] ?? false)
		{
			return true;
		}

		if (
			isset($this->arParams['SERVICE']['oauth_smtp_enabled'])
			&& $this->arParams['SERVICE']['oauth_smtp_enabled'] === true
			&& $this->isNotMicrosoftService($this->arParams['SERVICE'] ?? [])
		)
		{
			return true;
		}

		return false;
	}

	private function isNotMicrosoftService(?array $service): bool
	{
		$serviceName = $service['NAME'] ?? $service['name'] ?? null;

		return !in_array($serviceName, $this->getMicrosoftServiceNames(), true);
	}

	private function getMicrosoftServiceNames(): array
	{
		return [
			'office365',
			'exchangeOnline',
			'outlook.com',
		];
	}

	private function getMailboxSender(int $mailboxId, ?string $email = null, ?int $userId = null): ?array
	{
		$mailboxSender = null;
		$senders = Sender::getByParentId($mailboxId);
		if (!empty($senders))
		{
			foreach ($senders as $sender)
			{
				if ($mailboxSender
					|| empty($sender['OPTIONS']['smtp']['server'])
					|| !empty($sender['OPTIONS']['smtp']['encrypted'])
				)
				{
					Sender::delete([$sender['ID']]);

					continue;
				}

				$mailboxSender = $sender;
			}
		}

		if ($mailboxSender || empty($email))
		{
			return $mailboxSender;
		}

		$senders = Sender::getByEmail($email, $userId);
		if (empty($senders))
		{
			return null;
		}

		foreach ($senders as $sender)
		{
			$source = $sender['OPTIONS']['source'] ?? '';
			if (
				$source !== 'mail.client.config'
				|| empty($sender['OPTIONS']['smtp']['server'])
				|| !empty($sender['OPTIONS']['smtp']['encrypted'])
			)
			{
				continue;
			}

			if ($sender['PARENT_MODULE_ID'] !== 'mail' || (int)$sender['PARENT_ID'] !== $mailboxId)
			{
				Sender::updateSender(
					$sender['ID'],
					[
						'PARENT_MODULE_ID' => 'mail',
						'PARENT_ID' => $mailboxId,
					],
				);

				$sender['PARENT_MODULE_ID'] = 'mail';
				$sender['PARENT_ID'] = $mailboxId;
			}

			$mailboxSender = $sender;

			break;
		}

		return $mailboxSender;
	}

	private function getSenderName(string $name, ?int $userId = null): string
	{
		if (strlen($name) > 0)
		{
			return $name;
		}

		return Sender\UserSenderDataProvider::getUserFormattedName($userId) ?? '';
	}

	private static function deleteMailboxSender(int $mailboxId): void
	{
		$senders = Sender::getByParentId($mailboxId);
		$senderIds = [];
		$emails = [];
		foreach ($senders as $sender)
		{
			$senderIds[] = (int)$sender['ID'];
			if (!in_array($sender['EMAIL'], $emails, true))
			{
				$emails[] = $sender['EMAIL'];
			}
		}

		if (!empty($senderIds))
		{
			Main\Mail\Sender::delete($senderIds);
		}

		foreach ($emails as $email)
		{
			Main\Mail\Sender::clearCustomSmtpCache($email);
		}
	}

	private function prepareToolbarButton(bool $isMainMailPage = true): void
	{
		$button = $this->createMailboxGridButton()
			?? $this->createMassConnectButton()
			?? $this->createConnectionRequestButton()
		;

		$isConnectionRequest = $this->arParams['IS_CONNECTION_REQUEST_BUTTON'] ?? false;

		if (!$isConnectionRequest && !$isMainMailPage)
		{
			$button = $this->createConnectionRequestButton();
		}

		if ($isConnectionRequest)
		{
			$this->arParams['NEED_SHOW_TOOLBAR_GUIDE'] = !Mail\Helper\Config\Guide::wasConnectionRequestGuideShown();
			$this->arParams['TOOLBAR_GUIDE_OPTION_NAME'] = Mail\Helper\Config\Guide::getConnectionRequestGuideOptionName();
			$this->arParams['TOOLBAR_GUIDE_TITLE'] = Loc::getMessage('MAIL_CLIENT_CONFIG_CONNECTION_REQUEST_GUIDE_TITLE');
			$this->arParams['TOOLBAR_GUIDE_TEXT'] = Loc::getMessage('MAIL_CLIENT_CONFIG_CONNECTION_REQUEST_GUIDE_TEXT');
			$this->arParams['TOOLBAR_GUIDE_WIDTH'] = 430;
		}
		else
		{
			$this->arParams['NEED_SHOW_TOOLBAR_GUIDE'] = !Mail\Helper\Config\Guide::wasMailboxGridGuideShown();
			$this->arParams['TOOLBAR_GUIDE_OPTION_NAME'] = Mail\Helper\Config\Guide::getMailboxGridGuideOptionName();
			$this->arParams['TOOLBAR_GUIDE_TITLE'] = null;
			$this->arParams['TOOLBAR_GUIDE_TEXT'] = Loc::getMessage("MAIL_CLIENT_CONFIG_MAILBOX_GRID_GUIDE_TEXT");
		}

		if ($button !== null)
		{
			Toolbar::addButton($button);
		}
	}

	private function createMailboxGridButton(): ?Button
	{
		if (
			!$this->isAnyMailboxConnected()
			|| !Mail\Helper\MailAccess::hasCurrentUserAccessToMailboxGrid()
		)
		{
			return null;
		}

		$button = $this->createSliderButton(
			'/mail/mailbox-list',
			Loc::getMessage('MAIL_CLIENT_CONFIG_TOOLBAR_MAILBOXES_LIST'),
			Icon::LIST,
			'mailbox-grid-button',
			!LicenseManager::isMailboxManagementEnabled(),
			'limit_v2_mail_mailboxes_management_grid',
		);

		$pendingCount = (new Mail\Helper\Mailbox\MailboxConnectionRequestService())->getPendingCount();
		if ($pendingCount > 0)
		{
			$button->setCounter($pendingCount);
		}

		return $button;
	}

	private function createMassConnectButton(): ?Button
	{
		if (!Mail\Helper\MailAccess::hasCurrentUserAccessToMassConnect())
		{
			return null;
		}

		return $this->createSliderButton(
			'/mail/massconnect',
			Loc::getMessage('MAIL_CLIENT_CONFIG_TOOLBAR_MAILBOXES_MASS_CONNECT'),
			Icon::ADD,
			'mailbox-massconnect-button',
			!LicenseManager::isMailboxesMassConnectEnabled(),
			'limit_v2_mail_mailbox_massconnect',
			950,
		);
	}

	private function createConnectionRequestButton(): ?Button
	{
		if (!Mail\Helper\Config\Feature::isMailboxConnectionRequestAvailable())
		{
			return null;
		}

		$this->arParams['IS_CONNECTION_REQUEST_BUTTON'] = true;

		return new Button([
			'color' => Color::LIGHT_BORDER,
			'tag' => Tag::LINK,
			'text' => Loc::getMessage('MAIL_CLIENT_CONFIG_TOOLBAR_CONNECTION_REQUEST'),
			'onclick' => new JsCode('new BX.Mail.Client.Dialog.MailboxConnectionRequest().show();'),
			'icon' => Icon::MAIL_PLUS,
			'dataset' => [
				'toolbar-collapsed-icon' => Icon::MAIL_PLUS,
				'id' => 'mail-provider-showcase-mailbox-grid-button',
				'test-id' => 'mailbox-connection-request-button',
			],
		]);
	}

	private function createSliderButton(
		string $link,
		string $text,
		string $icon,
		string $testId,
		bool $tariffRestricted,
		string $featureTariffCode,
		?int $sliderWidth = null,
	): Button
	{
		$buttonParams = [
			'color' => Color::LIGHT_BORDER,
			'tag' => Tag::BUTTON,
			'text' => $text,
			'dataset' => [
				'toolbar-collapsed-icon' => $icon,
				'id' => 'mail-provider-showcase-mailbox-grid-button',
				'test-id' => $testId,
			],
		];

		if ($tariffRestricted)
		{
			$buttonParams['icon'] = Icon::LOCK;
			$buttonParams['onclick'] = new JsCode(
				"top.BX.UI.FeaturePromotersRegistry.getPromoter({code: '$featureTariffCode'}).show();",
			);
		}
		else
		{
			$sliderData = ['data' => ['source' => 'connect_page']];
			if ($sliderWidth !== null)
			{
				$sliderData['width'] = $sliderWidth;
			}
			$buttonParams['onclick'] = new JsCode(
				sprintf("BX.SidePanel.Instance.open('%s', %s)", $link, Json::encode($sliderData)),
			);
		}

		return new Button($buttonParams);
	}

	private function isAnyMailboxConnected(): bool
	{
		$row = Mail\MailboxTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=SERVER_TYPE' => 'imap',
			],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	private function shouldUseCustomSenderName(array $mailbox): bool
	{
		$ownerId = (int)($mailbox['USER_ID'] ?? 0);
		$senderName = $mailbox['USERNAME'] ?? '';

		if (isset($mailbox['OPTIONS']['useSenderName']))
		{
			return $mailbox['OPTIONS']['useSenderName'] && !empty($senderName);
		}

		if (empty($senderName) || Sender\UserSenderDataProvider::getUserFormattedName($ownerId) === $senderName)
		{
			return false;
		}

		return true;
	}
}
