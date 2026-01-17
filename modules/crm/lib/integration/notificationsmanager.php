<?php

namespace Bitrix\Crm\Integration;

use Bitrix\Booking\Provider\NotificationTemplateCodesProvider;
use Bitrix\Crm\Integration\ImOpenLines\GoToChat;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\ICanSendMessage;
use Bitrix\Crm\MessageSender\NotificationsPromoManager;
use Bitrix\Crm\Service\Container;
use Bitrix\ImConnector;
use Bitrix\ImOpenLines\Common;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\PhoneNumber\Format;
use Bitrix\Main\PhoneNumber\Formatter;
use Bitrix\Main\PhoneNumber\Parser;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Main\Web\Json;
use Bitrix\Notifications\Account;
use Bitrix\Notifications\Billing;
use Bitrix\Notifications\FeatureStatus;
use Bitrix\Notifications\Integration\Pull;
use Bitrix\Notifications\MessageStatus;
use Bitrix\Notifications\Model\ErrorCode;
use Bitrix\Notifications\Model\Message;
use Bitrix\Notifications\Model\MessageHistoryTable;
use Bitrix\Notifications\Model\MessageTable;
use Bitrix\Notifications\Model\QueueTable;
use Bitrix\Notifications\ProviderEnum;
use Bitrix\Notifications\Settings;
use Bitrix\Notifications\Template;

//use Bitrix\Main\DI\ServiceLocator;

Loc::loadMessages(__FILE__);

/**
 * Class NotificationsManager
 * @package Bitrix\Crm\Integration
 * @internal
 */
class NotificationsManager implements ICanSendMessage
{
	private const CONTACT_NAME_TEMPLATE_PLACEHOLDER = 'NAME';

	private const SALT = 'crm_notifications_template';
	private const SIGNATURE_TTL = '+7 days';

	/** @var bool */
	private static $canUse;

	/**
	 * All modules installed, notifications in general are available in this region and on current tariff
	 *
	 * @return bool
	 */
	public static function canUse(): bool
	{
		if (static::$canUse === null)
		{
			static::$canUse = (
				self::isAllModulesInstalled()
				&& \Bitrix\Notifications\Limit::isAvailable()
			);
		}

		return static::$canUse;
	}

	private static function isAllModulesInstalled(): bool
	{
		return Loader::includeModule('notifications')
			&& Loader::includeModule('imconnector')
		;
	}

	public static function getSenderCode(): string
	{
		return 'bitrix24';
	}

	/**
	 * @inheritDoc
	 */
	public static function isAvailable(): bool
	{
		if (!static::canUse())
		{
			return false;
		}

		return static::isCrmPaymentScenarioAvailableInRegion();
	}

	/**
	 * @inheritDoc
	 */
	public static function isConnected(): bool
	{
		return (
			static::canUse()
			&& (
				Settings::isScenarioEnabled(Settings::SCENARIO_CRM_PAYMENT)
				|| NotificationsPromoManager::isPromoSession()
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public static function getConnectUrl()
	{
		if (!static::canUse())
		{
			return null;
		}

		if (!static::isCrmPaymentScenarioAvailableInRegion())
		{
			return null;
		}

		if (Settings::getScenarioAvailability(Settings::SCENARIO_CRM_PAYMENT) === FeatureStatus::AVAILABLE)
		{
			if (
				Loader::includeModule('imopenlines')
				&& Settings::getScenarioAvailability(Settings::SCENARIO_CRM_PAYMENT) === FeatureStatus::AVAILABLE
			)
			{
				return Common::getAddConnectorUrl(
					defined('\Bitrix\ImConnector\Library::ID_NOTIFICATIONS_CONNECTOR')
						? ImConnector\Library::ID_NOTIFICATIONS_CONNECTOR
						: 'notifications'
				);
			}
		}
		else
		{
			return [
				'type' => 'ui_helper',
				//@TODO temporarily getting rid of imconnector dependency in crm 21.600.0
				//'value' => \Bitrix\ImConnector\Limit::INFO_HELPER_LIMIT_CONNECTOR_NOTIFICATIONS,
				'value' => 'limit_crm_sales_sms_whatsapp',
			];
		}

		return null;
	}

	public static function getUsageErrors(): array
	{
		if (!static::canUse())
		{
			return [];
		}

		$result = [];

		$maxPricePerMessage = Billing::getMaxMessagePrice();
		if (
			!(
				is_array($maxPricePerMessage)
				&& isset($maxPricePerMessage['PRICE'])
				&& Account::getBalance() >= (float)$maxPricePerMessage['PRICE']
			)
		)
		{
			$result[] = Loc::getMessage('CRM_NOTIFICATIONS_MANAGER_INSUFFICIENT_ACCOUNT_BALANCE');
		}

		return $result;
	}

	public static function getChannelsList(array $toListByType, int $userId): array
	{
		if (!self::isAllModulesInstalled())
		{
			return [];
		}

		return [
			new Channel(
				self::class,
				[
					'id' => self::getSenderCode(),
					'name' => Loc::getMessage('CRM_NOTIFICATIONS_MANAGER_CHANNEL_NAME'),
					'shortName' => Loc::getMessage('CRM_NOTIFICATIONS_MANAGER_CHANNEL_SHORT_NAME'),
					'isDefault' => true,
				],
				[
					new Channel\Correspondents\From(
						self::getSenderCode(),
						Loc::getMessage('CRM_NOTIFICATIONS_MANAGER_CHANNEL_SHORT_NAME'),
						Loc::getMessage('CRM_NOTIFICATIONS_MANAGER_CHANNEL_NAME'),
					),
				],
				$toListByType[\Bitrix\Crm\Multifield\Type\Phone::ID] ?? [],
				$userId,
			),
		];
	}

	public static function canSendMessageViaChannel(Channel $channel): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if (!self::isAllModulesInstalled())
		{
			return $result->addError(Channel\ErrorCode::getNotEnoughModulesError());
		}

		if (self::isAllModulesInstalled() && !self::canUse())
		{
			return $result->addError(Channel\ErrorCode::getNotAvailableError());
		}

		if (empty($channel->getFromList()))
		{
			// for consistency with other senders
			return $result->addError(Channel\ErrorCode::getNoFromError());
		}

		// we dont check crm-payment scenario here. GOTOCHAT for example dont use that scenario.

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	public static function canSendMessage()
	{
		return (
			static::canUse()
			&& static::isAvailable()
			&& static::isConnected()
		);
	}

	/**
	 * @inheritDoc
	 */
	public static function sendMessage(array $messageFields)
	{
		$templateCode = $messageFields['TEMPLATE_CODE'] ?? null;
		$languageId = isset($messageFields['LANGUAGE_ID']) ? (string)$messageFields['LANGUAGE_ID'] : '';

		$canSendMessage = (
			is_string($templateCode) && self::checkTemplateCode($templateCode, $languageId)
				? static::canUse()
				: static::canSendMessage()
		);

		if ($canSendMessage)
		{
			if (NotificationsPromoManager::isPromoSession())
			{
				NotificationsPromoManager::usePromo();
			}

			$message = Message::create($messageFields);
			if (
				isset($messageFields['ADDITIONAL_FIELDS']['SKIP_QUOTA'])
				&& $messageFields['ADDITIONAL_FIELDS']['SKIP_QUOTA'] === true
				&& method_exists($message, 'setSkipQuota')
			)
			{
				$message->setSkipQuota(true);
				unset($messageFields['ADDITIONAL_FIELDS']['SKIP_QUOTA']);
			}

			return $message->enqueue();
		}

		return false;
	}

	/**
	 * @inheritDoc
	 *
	 * @param array{
	 *     TEMPLATE_CODE: ?string,
	 *     PLACEHOLDERS: Array<string, mixed>,
	 *     LANGUAGE_ID: ?string,
	 *     ACTIVITY_PROVIDER_TYPE_ID: ?int,
	 *     MESSAGE_TEMPLATE: ?string,
	 * } $options
	 */
	public static function makeMessageFields(array $options, array $commonOptions): array
	{
		$phoneNumber = Parser::getInstance()->parse($commonOptions['PHONE_NUMBER']);
		$e164PhoneNumber = $phoneNumber->isValid()
			? Formatter::format($phoneNumber, Format::E164)
			: null;

		$templateCode = $options['TEMPLATE_CODE'] ?? '';

		if (
			isset($options['PLACEHOLDERS'][self::CONTACT_NAME_TEMPLATE_PLACEHOLDER])
			&& self::doesTemplateUtilizeName($templateCode)
			&& \CAllCrmContact::isDefaultName($options['PLACEHOLDERS'][self::CONTACT_NAME_TEMPLATE_PLACEHOLDER])
		)
		{
			$options['PLACEHOLDERS'][self::CONTACT_NAME_TEMPLATE_PLACEHOLDER] = $e164PhoneNumber;
		}

		$result = [
			'TEMPLATE_CODE' => $templateCode,
			'PLACEHOLDERS' => $options['PLACEHOLDERS'],
			'USER_ID' => $commonOptions['USER_ID'],
			'PHONE_NUMBER' => $e164PhoneNumber,
			'LANGUAGE_ID' => $options['LANGUAGE_ID'] ?? LANGUAGE_ID,
			'ADDITIONAL_FIELDS' => array_merge(
				$commonOptions['ADDITIONAL_FIELDS'],
				[
					'ACTIVITY_PROVIDER_TYPE_ID' => $options['ACTIVITY_PROVIDER_TYPE_ID'] ?? null,
					'ACTIVITY_AUTHOR_ID' => $commonOptions['USER_ID'],
					'ACTIVITY_DESCRIPTION' => '',
					'MESSAGE_TO' => $commonOptions['PHONE_NUMBER'],
				]
			),
		];

		if (!empty($options['MESSAGE_TEMPLATE']))
		{
			$result['MESSAGE_TEMPLATE'] = $options['MESSAGE_TEMPLATE'];
		}

		if (
			NotificationsPromoManager::isPromoSession()
			&& static::canUse()
			&& static::isAvailable()
			&& !Settings::isScenarioEnabled(Settings::SCENARIO_CRM_PAYMENT)
		)
		{
			$result['IS_TEST'] = true;
		}

		return $result;
	}

	/**
	 * @param string $templateCode
	 * @return bool
	 */
	private static function doesTemplateUtilizeName(string $templateCode): bool
	{
		$nameUtilizingTemplates = [
			'ORDER_LINK',
			'ORDER_PAID',
			'ORDER_COMPLETED',
			'ORDER_IN_WORK',
			'ORDER_READY_2',
			'ORDER_IN_TRANSIT',
			'ORDER_ISSUED_COURIER',
		];

		return in_array($templateCode, $nameUtilizingTemplates, true);
	}

	/**
	 * @param int $messageId
	 * @param array $options
	 * @return array
	 */
	public static function getMessageByInfoId(int $messageId, array $options = []): array
	{
		$result = [
			'MESSAGE' => null,
			'HISTORY_ITEMS' => null,
			'QUEUE_ITEM' => null,
		];

		if (!static::canUse())
		{
			return $result;
		}

		$needHistory = (bool)($options['needHistory'] ?? true);
		$needQueueItem = (bool)($options['needQueueItem'] ?? false);

		$message = MessageTable::getByPrimary($messageId)->fetch();
		if ($message)
		{
			static::setStatusData($message);
			$result['MESSAGE'] = $message;
		}

		if ($needHistory)
		{
			$result['HISTORY_ITEMS'] = static::getHistory($messageId);
		}

		if ($needQueueItem)
		{
			$result['QUEUE_ITEM'] = static::getQueueItem($messageId);
		}

		return $result;
	}

	/**
	 * @return string|null
	 */
	public static function getPullTagName(): ?string
	{
		return static::canUse() ? Pull::TAG_ANY_MESSAGE : null;
	}

	/**
	 * @param int $messageId
	 * @return array
	 */
	private static function getHistory(int $messageId): array
	{
		$result = [];

		$historyList = MessageHistoryTable::getList([
			'filter' => [
				'MESSAGE_ID' => $messageId,
			],
			'order' => [
				'SERVER_DATE' => 'DESC',
			],
		]);

		while ($historyItem = $historyList->fetch())
		{
			$historyItem['ERROR_MESSAGE'] = $historyItem['ERROR_CODE']
				? ErrorCode::getLocalized($historyItem['ERROR_CODE'])
				: $historyItem['REASON'];

			static::setStatusData($historyItem);
			static::setProviderData($historyItem);

			$result[] = $historyItem;
		}

		return $result;
	}

	/**
	 * @param int $messageId
	 * @return array|null
	 */
	private static function getQueueItem(int $messageId): ?array
	{
		$queueItem = QueueTable::getByPrimary($messageId)->fetch();

		return $queueItem ?: null;
	}

	/**
	 * @param array $item
	 * @param string $statusField
	 * @param string $dataField
	 */
	private static function setStatusData(
		array &$item,
		string $statusField = 'STATUS',
		string $dataField = 'STATUS_DATA'
	): void
	{
		if (!isset($item[$statusField]))
		{
			return;
		}
		$status = $item[$statusField];

		$statusDescriptions = MessageStatus::getDescriptions();
		$statusSemantics = MessageStatus::getSemantics();

		$item[$dataField] = [
			'DESCRIPTION' => $statusDescriptions[$status] ?? null,
			'SEMANTICS' => $statusSemantics[$status] ?? null,
			'IS_FAILURE' => $statusSemantics[$status] === MessageStatus::SEMANTIC_FAILURE,
		];
	}

	/**
	 * @param array $item
	 * @param string $providerField
	 * @param string $dataField
	 */
	private static function setProviderData(
		array &$item,
		string $providerField = 'PROVIDER_CODE',
		string $dataField = 'PROVIDER_DATA'
	): void
	{
		if (!isset($item[$providerField]))
		{
			return;
		}
		$provider = $item[$providerField];

		$providerDescriptions = ProviderEnum::getDescriptions();

		$item[$dataField] = [
			'DESCRIPTION' => $providerDescriptions[$provider] ?? null,
		];
	}

	public static function showSignUpFormOnCrmShopCreated(): void
	{
		$connectUrl = (static::canUse() && !static::isConnected()) ? static::getConnectUrl() : null;
		?>
		<script>
			BX.ready(
				function()
				{
					var key = 'crmShopMasterJustFinished';
					var crmShopMasterJustFinished = localStorage.getItem(key);
					if (crmShopMasterJustFinished === 'Y')
					{
						<?if (is_string($connectUrl)):?>
							BX.SidePanel.Instance.open("<?=\CUtil::JSescape($connectUrl)?>");
						<?elseif (is_array($connectUrl) && isset($connectUrl['type'])):?>
							<?if ($connectUrl['type'] === 'ui_helper'):?>
								BX.loadExt('ui.info-helper').then(() =>
								{
									BX.UI.InfoHelper.show("<?=\CUtil::JSescape($connectUrl['value'])?>");
								});
							<?endif;?>
						<?endif;?>

						localStorage.removeItem(key);
					}
				}
			);
		</script>
		<?
	}

	/**
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 * @see \Bitrix\ImConnector\Tools\Connectors\Notifications::isEnabled
	 */
	final public static function isCrmPaymentScenarioAvailableInRegion(): bool
	{
		if (!Loader::includeModule('notifications'))
		{
			return false;
		}

		return Settings::getScenarioAvailability(Settings::SCENARIO_CRM_PAYMENT) !== FeatureStatus::UNAVAILABLE;
	}

	final public static function isCrmPaymentScenarioLimited(): bool
	{
		if (!Loader::includeModule('notifications'))
		{
			return true;
		}

		return Settings::getScenarioAvailability(Settings::SCENARIO_CRM_PAYMENT) === FeatureStatus::LIMITED;
	}

	private static function checkTemplateCode(string $templateCode, string $languageId): bool
	{
		$onlyRuTemplates = [
			GoToChat::NOTIFICATIONS_MESSAGE_CODE,
			Calendar\Notification\NotificationService::TEMPLATE_SHARING_EVENT_INVITATION,
			Calendar\Notification\NotificationService::TEMPLATE_SHARING_EVENT_AUTO_ACCEPTED,
			Calendar\Notification\NotificationService::TEMPLATE_SHARING_EVENT_CANCELLED_LINK_ACTIVE,
			Calendar\Notification\NotificationService::TEMPLATE_SHARING_EVENT_CANCELLED,
			Calendar\Notification\NotificationService::TEMPLATE_SHARING_EVENT_EDITED,
		];
		if (
			$languageId === 'ru'
			&& in_array($templateCode, $onlyRuTemplates, true)
		)
		{
			return true;
		}

		$allLangTemplates = [];

		if (Loader::includeModule('booking'))
		{
			foreach (NotificationTemplateCodesProvider::getAll() as $bookingTemplate)
			{
				$allLangTemplates[] = $bookingTemplate;
			}
		}

		return in_array($templateCode, $allLangTemplates, true);
	}

	/**
	 * @param string $code Template code
	 * @param string|null $lang By default - default notification language
	 * @return null|array{
	 *      LANGUAGE_ID: string,
	 *      TITLE: string,
	 *      TEXT: string,
	 *      TEXT_SMS: string
	 *  } - null on error, empty array if not found
	 */
	final public static function getTemplateTranslation(string $code, ?string $lang = null): ?array
	{
		if (!Loader::includeModule('notifications'))
		{
			return null;
		}

		$lang ??= self::getDefaultNotificationLanguageId();

		$result = Template::getTranslations($code, $lang);
		if (!$result->isSuccess())
		{
			Container::getInstance()->getLogger('Default')->error(
				'{method}: Failed to get template translations for {code} {errors}',
				[
					'method' => __METHOD__,
					'code' => $code,
					'lang' => $lang,
					'errors' => $result->getErrors(),
				],
			);

			return null;
		}

		$data = $result->getData();
		$translation = $data[0] ?? null;

		return is_array($translation) ? $translation : [];
	}

	final public static function getDefaultNotificationLanguageId(): string
	{
		return \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?? LANGUAGE_ID;
	}

	/**
	 * @param string $templateCode
	 * @param array<array{name: string, value: string|null>|null $placeholders
	 * @return string
	 */
	final public static function signTemplate(string $templateCode, ?array $placeholders): string
	{
		$payload = [
			'template' => $templateCode,
		];
		if (is_array($placeholders))
		{
			$payload['placeholders'] = self::normalizeSignablePlaceholders($placeholders);
		}

		$serializedPayload = base64_encode(Json::encode($payload));

		$signer = new TimeSigner();

		return $signer->sign($serializedPayload, self::SIGNATURE_TTL, self::SALT);
	}

	private static function normalizeSignablePlaceholders(array $placeholders): array
	{
		$result = [];

		foreach ($placeholders as $placeholder)
		{
			if (!is_array($placeholder))
			{
				continue;
			}

			if (!isset($placeholder['name']) || !is_string($placeholder['name']))
			{
				continue;
			}

			$normalized = ['name' => $placeholder['name']];

			if (array_key_exists('value', $placeholder))
			{
				$value = $placeholder['value'];

				if (!is_string($value) && !is_null($value))
				{
					continue;
				}

				$normalized['value'] = $value;
			}

			$result[] = $normalized;
		}

		return $result;
	}


	/**
	 * @param string $signedTemplate
	 * @return null|array{
	 *     template: string,
	 *     placeholders?: array<array{name: string, value: string|null}>
	 * } - null on error
	 */
	final public static function unsignTemplate(string $signedTemplate): ?array
	{
		$signer = new TimeSigner();

		try
		{
			$serializedPayload = $signer->unsign($signedTemplate, self::SALT);
		}
		catch (BadSignatureException)
		{
			return null;
		}

		try
		{
			$payload = Json::decode(base64_decode($serializedPayload));
		}
		catch (ArgumentException)
		{
			return null;
		}

		if (!is_array($payload))
		{
			return null;
		}

		if (!isset($payload['template']) || !is_string($payload['template']))
		{
			return null;
		}

		$normalizedPayload = [
			'template' => $payload['template'],
		];
		if (isset($payload['placeholders']) && is_array($payload['placeholders']))
		{
			$normalizedPayload['placeholders'] = self::normalizeSignablePlaceholders($payload['placeholders']);
		}

		return $normalizedPayload;
	}
}
