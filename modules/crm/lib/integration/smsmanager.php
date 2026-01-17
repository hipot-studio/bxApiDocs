<?php

namespace Bitrix\Crm\Integration;

use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Communications;
use Bitrix\Crm\Feature;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\Channel\Correspondents\From;
use Bitrix\Crm\MessageSender\ICanSendMessage;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Crm\Settings;
use Bitrix\Main;
use Bitrix\MessageService;

/**
 * Class SmsManager
 * @package Bitrix\Crm\Integration
 * @internal
 */
class SmsManager implements ICanSendMessage
{
	static $canUse = null;

	/**
	 * @return bool
	 */
	public static function canUse(): bool
	{
		if (static::$canUse === null)
		{
			static::$canUse = Main\Loader::includeModule('messageservice');
		}
		return static::$canUse;
	}

	/**
	 * @inheritDoc
	 */
	public static function getSenderCode(): string
	{
		return 'sms_provider';
	}

	/**
	 * @inheritDoc
	 */
	public static function isAvailable(): bool
	{
		return static::canUse();
	}

	/**
	 * @inheritDoc
	 */
	public static function isConnected(): bool
	{
		if (static::canUse())
		{
			return MessageService\Sender\SmsManager::getUsableSender() !== null;
		}

		return false;
	}

	/**
	 * @inheritDoc
	 * @return string|null
	 */
	public static function getConnectUrl(): ?string
	{
		if (!static::canUse())
		{
			return null;
		}

		return (new Main\Web\Uri(
			getLocalPath(
				'components' . \CComponentEngine::makeComponentPath('bitrix:salescenter.smsprovider.panel') . '/slider.php'
			)
		))->getLocator();
	}

	/**
	 * @inheritDoc
	 */
	public static function getUsageErrors(): array
	{
		return [];
	}

	public static function getChannelsList(array $toListByType, int $userId): array
	{
		$channels = [];

		foreach (self::getSenderInfoList(true) as $channelInfo)
		{
			$fromList = [];
			foreach ($channelInfo['fromList'] as $fromInfo)
			{
				$fromList[] = new From(
					(string)($fromInfo['id'] ?? ''),
					(string)($fromInfo['name'] ?? ''),
					isset($fromInfo['description']) ? (string)$fromInfo['description'] : null,
					isset($fromInfo['isDefault']) && is_bool($fromInfo['isDefault']) ? $fromInfo['isDefault'] : false,
					true,
					isset($fromInfo['type']) ? (string)$fromInfo['type'] : null,
				);
			}

			$channels[] = new Channel(
				self::class,
				$channelInfo,
				$fromList,
				$toListByType[\Bitrix\Crm\Multifield\Type\Phone::ID] ?? [],
				$userId,
			);
		}

		return $channels;
	}

	public static function canSendMessageViaChannel(Channel $channel): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if (!self::canUse())
		{
			return $result->addError(Channel\ErrorCode::getNotEnoughModulesError());
		}

		$sender = self::getSenderById($channel->getId());
		if (!$sender)
		{
			return $result->addError(Channel\ErrorCode::getUnknownChannelError());
		}

		if (!$sender->canUse())
		{
			return $result->addError(Channel\ErrorCode::getUnusableChannelError());
		}

		if (empty($channel->getFromList()))
		{
			// from is required field for sending message
			/** @see \Bitrix\MessageService\Message::checkFields */
			return $result->addError(Channel\ErrorCode::getNoFromError());
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	public static function canSendMessage()
	{
		return static::isConnected();
	}

	/**
	 * @return array Simple list of senders, array(id => name)
	 */
	public static function getSenderSelectList()
	{
		$list = array();
		if (static::canUse())
		{
			$list = MessageService\Sender\SmsManager::getSenderInfoList();
		}
		return $list;
	}

	/**
	 * @param string $senderId Sender id.
	 * @return array Simple list of sender From aliases
	 */
	public static function getSenderFromList($senderId)
	{
		$list = array();
		if (static::canUse())
		{
			$sender = self::getSenderById($senderId);
			if ($sender)
			{
				$defaultFrom = $sender->getDefaultFrom();
				foreach ($sender->getFromList() as $fromInfo)
				{
					$list[] = $fromInfo + [
						'isDefault' => ($fromInfo['id'] === $defaultFrom),
					];
				}
			}
		}
		return $list;
	}

	/**
	 * @param bool $getFromList
	 * @return array Senders information.
	 */
	public static function getSenderInfoList($getFromList = false)
	{
		$info = array();
		if (static::canUse())
		{
			$default = MessageService\Sender\SmsManager::getDefaultSender();

			foreach (MessageService\Sender\SmsManager::getSenders() as $sender)
			{
				$senderInfo = array(
					'id' => $sender->getId(),
					'isConfigurable' => $sender->isConfigurable(),
					'name' => $sender->getName(),
					'shortName' => $sender->getShortName(),
					'canUse' => $sender->canUse(),
					'isDemo' => $sender->isConfigurable() ? $sender->isDemo() : null,
					'isDefault' => ($default && $default->getId() === $sender->getId()),
					'manageUrl' => $sender->getManageUrl(),
					'isTemplatesBased' => $sender->isConfigurable() ? $sender->isTemplatesBased() : false,
					'templates' => null, // will be loaded asynchronously
				);

				if ($getFromList)
				{
					$senderInfo['fromList'] = static::getSenderFromList($sender->getId());
				}

				$info[] = $senderInfo;
			}
		}

		return $info;
	}

	public static function getSenderById(string $senderId): ?MessageService\Sender\Base
	{
		return static::canUse()
			? MessageService\Sender\SmsManager::getSenderById($senderId)
			: null;
	}

	public static function isEdnaWhatsAppSendingEnabled(string $senderId): bool
	{
		return $senderId === 'ednaru'
			&& Settings\Crm::isWhatsAppScenarioEnabled()
		;
	}

	public static function getSenderShortName($senderId)
	{
		$name = '';
		if (static::canUse())
		{
			$sender = self::getSenderById($senderId);
			if ($sender)
			{
				$name = $sender->getShortName();
			}
		}

		return $name;
	}

	public static function getSenderFromName($senderId, $from)
	{
		$name = '';
		if (static::canUse())
		{
			$sender = self::getSenderById($senderId);
			if ($sender)
			{
				$fromList = $sender->getFromList();
				foreach ($fromList as $fromItem)
				{
					if ($fromItem['id'] === $from)
					{
						$name = $fromItem['name'];
						break;
					}
				}
			}
		}
		return $name;
	}

	final public static function getSenderFromType(string $senderId, string $fromId): ?string
	{
		$sender = self::getSenderById($senderId);
		if (!$sender)
		{
			return null;
		}

		$fromList = $sender->getFromList();
		foreach ($fromList as $fromItem)
		{
			if ((string)$fromItem['id'] === $fromId && isset($fromItem['type']))
			{
				return (string)$fromItem['type'];
			}
		}

		return null;
	}

	/**
	 * @param array $messageFields
	 * @return Main\Entity\AddResult|false
	 */
	public static function sendMessage(array $messageFields)
	{
		if (static::canUse())
		{
			return MessageService\Sender\SmsManager::sendMessage($messageFields);
		}
		return false;
	}

	/**
	 * @return array
	 */
	public static function getMessageStatusDescriptions()
	{
		if (static::canUse())
		{
			return MessageService\MessageStatus::getDescriptions();
		}
		return array();
	}

	public static function getMessageStatusSemantics()
	{
		if (static::canUse())
		{
			return MessageService\MessageStatus::getSemantics();
		}
		return array();
	}

	/**
	 * Returns list of registered SMS services
	 * @return array
	 */
	public static function getRegisteredSmsSenderList(): array
	{
		if (static::canUse())
		{
			return MessageService\Sender\SmsManager::getRegisteredSenderList();
		}

		return [];
	}

	/**
	 * @param int $id
	 * @return string
	 */
	public static function getMessageStatus($id)
	{
		if (static::canUse())
		{
			$result = MessageService\Sender\SmsManager::getMessageStatus($id);
			if ($result->isSuccess())
			{
				return $result->getStatusText();
			}
		}
		return '';
	}

	/**
	 * @param int $statusId
	 * @return bool
	 */
	public static function isMessageErrorStatus($statusId)
	{
		if (static::canUse())
		{
			return (int)$statusId === MessageService\MessageStatus::ERROR;
		}
		return false;
	}

	/**
	 * @param int $messageId
	 * @return array|false
	 */
	public static function getMessageFields($messageId)
	{
		if (static::canUse())
		{
			return MessageService\Message::getFieldsById($messageId);
		}
		return false;
	}

	/**
	 * @return string
	 */
	public static function getManageUrl()
	{
		return '/crm/configs/sms/';
	}

	/**
	 * @param int $entityTypeId
	 * @param int $entityId
	 * @return array
	 */
	public static function getEditorConfig($entityTypeId, $entityId)
	{
		$entityTypeId = (int)$entityTypeId;
		$entityId = (int)$entityId;
		$result = array(
			'canUse' => static::canUse(),
			'canSendMessage' => static::canSendMessage(),
			'manageUrl' => static::getManageUrl(),
			'senders' => static::getSenderInfoList(true),
			'defaults' => static::getEditorDefaults(),
			'communications' => array()
		);

		if ($entityId > 0)
		{
			$result['communications'] = static::getEntityPhoneCommunications($entityTypeId, $entityId);
		}

		return $result;
	}

	/**
	 * @param array $defaults
	 */
	public static function setEditorDefaults(array $defaults)
	{
		$config = array(
			'senderId' => isset($defaults['senderId']) ? (string)$defaults['senderId'] : null,
			'from' => isset($defaults['from']) ? (string)$defaults['from'] : null
		);
		\CUserOptions::SetOption('crm', 'sms_manager_editor', $config);
	}

	/**
	 * @return array
	 */
	public static function getEditorDefaults()
	{
		return (array)\CUserOptions::GetOption('crm', 'sms_manager_editor', self::getEditorCommon());
	}

	/**
	 * Sets default parameters for all users
	 * @param array $defaults
	 * @return void
	 */
	public static function setEditorDefaultsCommon(array $defaults)
	{
		$config = array(
			'senderId' => isset($defaults['senderId']) ? (string)$defaults['senderId'] : null,
			'from' => isset($defaults['from']) ? (string)$defaults['from'] : null
		);
		\Bitrix\Main\Config\Option::set('crm', 'sms_manager_editor', serialize($config));
	}

	/**
	 * @return array
	 */
	public static function getEditorCommon()
	{
		return (array)unserialize(\Bitrix\Main\Config\Option::get('crm', 'sms_manager_editor', serialize(array('senderId' => null, 'from' => null))), ['allowed_classes' => false]);
	}

	/**
	 * @param int $entityTypeId
	 * @param int $entityId
	 * @return array
	 */
	public static function getEntityPhoneCommunications($entityTypeId, $entityId)
	{
		return (new Communications((int)$entityTypeId, (int)$entityId))->setCheckPermissions(false)->get();
	}

	/**
	 * @inheritDoc
	 *
	 * @param array{
	 *     SENDER_ID: ?string,
	 *     MESSAGE_FROM: ?string,
	 *     MESSAGE_BODY: string,
	 *     MESSAGE_TEMPLATE: ?string,
	 *     ACTIVITY_PROVIDER_TYPE_ID: int,
	 * } $options
	 */
	public static function makeMessageFields(array $options, array $commonOptions): array
	{
		$sender = (isset($options['SENDER_ID']))
			? MessageService\Sender\SmsManager::getSenderById($options['SENDER_ID'])
			: MessageService\Sender\SmsManager::getUsableSender();

		$fields = [
			'SENDER_ID' => $sender->getId(),
			'MESSAGE_FROM' => $options['MESSAGE_FROM'] ?? $sender->getFirstFromList(),
			'MESSAGE_BODY' => $options['MESSAGE_BODY'],
			'AUTHOR_ID' => $commonOptions['USER_ID'],
			'MESSAGE_TO' => $commonOptions['PHONE_NUMBER'],
			'MESSAGE_HEADERS' => [
				'module_id' => 'crm',
				'bindings' => $commonOptions['ADDITIONAL_FIELDS']['BINDINGS'] ?? [],
			],
			'ADDITIONAL_FIELDS' => array_merge(
				$commonOptions['ADDITIONAL_FIELDS'],
				[
					'ACTIVITY_PROVIDER_ID' => $options['ACTIVITY_PROVIDER_ID'] ?? null,
					'ACTIVITY_PROVIDER_TYPE_ID' => $options['ACTIVITY_PROVIDER_TYPE_ID'] ?? null,
					'ACTIVITY_AUTHOR_ID' => $commonOptions['USER_ID'],
					'ACTIVITY_DESCRIPTION' => $options['MESSAGE_BODY'],
					'MESSAGE_TO' => $commonOptions['PHONE_NUMBER'],
					'SENDER_ID' => $sender->getId(),
				]
			),
		];

		if (!empty($options['MESSAGE_TEMPLATE']))
		{
			$fields['MESSAGE_TEMPLATE'] = $options['MESSAGE_TEMPLATE'];
		}

		return $fields;
	}

	final public static function getActivityProviderId(string $channelId, ?string $fromType): string
	{
		if (Taxonomy::isWhatsAppByParams(self::getSenderCode(), $channelId, $fromType))
		{
			return \Bitrix\Crm\Activity\Provider\WhatsApp::getId();
		}

		if (Feature::enabled(Feature\TelegramActivity::class) && Taxonomy::isTelegramByParams(self::getSenderCode(), $channelId, $fromType))
		{
			return \Bitrix\Crm\Activity\Provider\Telegram::getId();
		}

		return \Bitrix\Crm\Activity\Provider\Sms::getId();
	}
}
