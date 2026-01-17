<?php
namespace Bitrix\ImConnector\Connectors;

use Bitrix\ImConnector\Connector;
use Bitrix\ImConnector\InteractiveMessage\Connectors\Wazzup\Output;
use Bitrix\ImConnector\Library;
use Bitrix\ImConnector\Tools\Text;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class Wazzup
 * @package Bitrix\ImConnector\Connectors
 */
class Wazzup extends Base
{
	public const WAZZUP_SERVICE_TITLE = 'Wazzup';

	public static function hideApiKey(?string $apiKey = null): string
	{
		if (empty($apiKey))
		{
			return '';
		}

		$length = strlen($apiKey);
		$maskedApiKey = substr($apiKey, 0, 3)
			. str_repeat('*', max(0, $length - 6))
			. substr($apiKey, -3);

		return $maskedApiKey;
	}

	public static function getChannelTitle(string $channelType, string $channelName, bool $includeServiceName): string
	{
		$titleParts = [];
		if ($includeServiceName)
		{
			$titleParts[] = self::WAZZUP_SERVICE_TITLE;
		}

		$titleParts[] = $channelType;
		$titleParts[] = $channelName;

		return implode(' ', $titleParts);
	}

	public function sendMessageProcessing(array $message, $line): array
	{
		$message = Output::processSendingMessage($message, $this->idConnector);
		$message = $this->processMessageForRich($message);
		$message['message']['text'] = Text::parseQuoting($message['message']['text']);

		unset($message['user']);

		return $message;
	}

	public static function sendNotifyNewWazzupAgent(): string
	{
		if (\Bitrix\Main\Config\Option::get('imconnector', 'feature_wazzup', 'N') !== 'Y')
		{
			return __METHOD__ . '();';
		}

		if (
			!Loader::includeModule('im')
			|| !Loader::includeModule('market')
			|| !Loader::includeModule('rest')
		)
		{
			return '';
		}

		$portalRegion = Connector::getPortalRegion();
		if (!Connector::isAllowedConnectorInRegion(Library::ID_WAZZUP_CONNECTOR, $portalRegion))
		{
			return '';
		}

		$app = \Bitrix\Market\Application\Installed::getByCode('wazzup.app');
		if (!empty($app))
		{
			$notificationFields = [
				'NOTIFY_TYPE' => \IM_NOTIFY_SYSTEM,
				'NOTIFY_MODULE' => 'imconnector',
				'NOTIFY_EVENT' => 'admin_notification',
				'NOTIFY_TITLE' => Loc::getMessage('IMCONNECTOR_WAZZUP_NOTIFY_TITLE'),
				'NOTIFY_MESSAGE' => Loc::getMessage('IMCONNECTOR_WAZZUP_NOTIFY_MESSAGE', [
					'#LINK_START#' => (
					(Loader::includeModule('bitrix24'))
						? '[URL=/contact_center/connector/?ID=wazzup]'
						: '[URL=/services/contact_center/connector/?ID=wazzup]'),
					'#LINK_END#' => '[/URL]',
				]),
			];

			$adminIds = self::getAdminIds();
			foreach ($adminIds as $adminId)
			{
				$notificationFields['TO_USER_ID'] = $adminId;
				\CIMNotify::Add($notificationFields);
			}
		}

		return '';
	}

	private static function getAdminIds(): array
	{
		$adminIds = [];
		if (Loader::includeModule('bitrix24'))
		{
			$adminIds = \CBitrix24::getAllAdminId();
		}
		else
		{
			$result = \CGroup::GetGroupUserEx(1);
			while ($row = $result->fetch())
			{
				$adminIds[] = (int)$row['USER_ID'];
			}
		}

		return $adminIds;
	}
}
