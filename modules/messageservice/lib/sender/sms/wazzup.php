<?php
namespace Bitrix\MessageService\Sender\Sms;

use Bitrix\ImConnector\Library;
use Bitrix\ImConnector\Status;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\MessageService\MessageStatus;
use Bitrix\MessageService\Sender;

class Wazzup extends Sender\BaseConfigurable
{
	public const ID = 'wazzup';
	public const NAME = 'Wazzup';
	public const AVAILABLE_CHANNELS = [
		'tgapi',
		'whatsapp',
	];

	public function getId()
	{
		return static::ID;
	}

	public function getName()
	{
		return self::NAME;
	}

	public function getShortName()
	{
		return $this->getName();
	}

	public function getFromList()
	{
		$connectors = Status::getInstanceAllLine(Library::ID_WAZZUP_CONNECTOR);

		$result = [];
		foreach ($connectors as $connector)
		{
			if ($connector->isStatus())
			{
				$connectorData = $connector->getData();
				if (!in_array($connectorData['channelType'], self::AVAILABLE_CHANNELS, true))
				{
					continue;
				}

				$result[] = [
					'id' => $connectorData['channelId'],
					'type' => $connectorData['channelType'],
					'name' => \Bitrix\ImConnector\Connectors\Wazzup::getChannelTitle($connectorData['channelType'], $connectorData['channelName'], true),
				];
			}
		}

		return $result;
	}

	public function isCorrectFrom($from)
	{
		return parent::isCorrectFrom($from);
	}

	public function sendMessage(array $messageFields): Sender\Result\SendMessage
	{
		$result = new Sender\Result\SendMessage();

		if (!$this->canUse())
		{
			return $result->addError(new Error('Service is unavailable'));
		}

		$lineId = $this->getLineIdFromChannelId($messageFields['MESSAGE_FROM']);
		if (!$lineId)
		{
			return $result->addError(new Error('Unknown Channel ID', 'UNKNOWN_CHANNEL_ID'));
		}

		$connector = Status::getInstance(self::ID, $lineId);
		$connectorData = $connector->getData();

		$messageData = [
			'message' => [
				'text' => $messageFields['MESSAGE_BODY']
			],
			'chat' => [
				'id' => $messageFields['MESSAGE_TO'],
				'transport' => $connectorData['channelType'],
			]
		];

		$connectorOutput = new \Bitrix\ImConnector\Output(self::ID, $lineId);
		$connectorOutput->setWaitResponse(true);
		$connectorResult = $connectorOutput->sendMessage([$messageData]);

		if (!$connectorResult->isSuccess())
		{
			$result->addErrors($connectorResult->getErrors());
		}
		else
		{
			$connectorData = $connectorResult->getData();
			if (count($connectorData) > 0)
			{
				$result->setExternalId($connectorData[0]['message']['id'][0]);
				$result->setStatus(\Bitrix\MessageService\MessageStatus::SENDING);
			}
		}

		return $result;
	}

	public static function onReceivedStatusDelivered(Event $event): bool
	{
		$params = $event->getParameters();
		if (empty($params) || empty($params['id']))
		{
			return false;
		}

		$message = \Bitrix\MessageService\Message::loadByExternalId(self::ID, $params['id']);
		if ($message && $message->getStatusId() < MessageStatus::DELIVERED)
		{
			return $message->updateStatus(MessageStatus::DELIVERED);
		}

		return false;
	}

	public static function onReceivedStatusRead(Event $event): bool
	{
		$params = $event->getParameters();
		if (empty($params) || empty($params['id']))
		{
			return false;
		}

		$message = \Bitrix\MessageService\Message::loadByExternalId(self::ID, $params['id']);
		if ($message && $message->getStatusId() < MessageStatus::READ)
		{
			return $message->updateStatus(MessageStatus::READ);
		}

		return false;
	}

	public function canUse()
	{
		return $this->isRegistered();
	}

	public function isRegistered()
	{
		$connectors = Status::getInstanceAllLine(Library::ID_WAZZUP_CONNECTOR);
		foreach ($connectors as $connector)
		{
			if ($connector->isStatus())
			{
				return true;
			}
		}

		return false;
	}

	public function getExternalManageUrl()
	{
		return '';
	}

	public function getMessageStatus(array $messageFields)
	{
		$result = new Sender\Result\MessageStatus();

		return $result;
	}

	public function register(array $fields)
	{
		// TODO: Implement register() method.
	}

	public function getOwnerInfo()
	{
		// TODO: Implement getOwnerInfo() method.
	}

	public function isTemplatesBased(): bool
	{
		return false;
	}

	public function getManageUrl(): string
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return '';
		}

		$contactCenterUrl = \Bitrix\ImOpenLines\Common::getContactCenterPublicFolder();

		return $contactCenterUrl . 'connector/?ID=' . Library::ID_WAZZUP_CONNECTOR;
	}

	public static function isSupported()
	{
		if (\Bitrix\Main\Application::getInstance()->getLicense()->getRegion() !== 'ru')
		{
			return false;
		}

		if (
			\Bitrix\Main\Config\Option::get('imconnector', 'feature_wazzup', 'N') === 'Y'
			||
			(
				\Bitrix\Main\Loader::includeModule('crm')
				&& class_exists(\Bitrix\Crm\Feature\TelegramActivity::class)
				&& \Bitrix\Crm\Feature::enabled(\Bitrix\Crm\Feature\TelegramActivity::class)
			)
		)
		{
			return parent::isSupported();
		}

		return false;
	}

	private function getLineIdFromChannelId(string $channelId): ?int
	{
		if (empty($channelId))
		{
			return null;
		}

		$statuses = Status::getInstanceAllLine(Library::ID_WAZZUP_CONNECTOR);
		foreach ($statuses as $status)
		{
			$statusData = $status->getData();
			if (
				$status->isStatus()
				&& isset($statusData['channelId'])
				&& $statusData['channelId'] === $channelId
			)
			{
				return $status->getLine();
			}
		}

		return null;
	}
}
