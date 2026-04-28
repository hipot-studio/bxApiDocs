<?php

namespace Bitrix\ImConnector\Connectors;

use Bitrix\ImConnector\Connector;
use Bitrix\ImConnector\Error;
use Bitrix\ImConnector\Library;
use Bitrix\ImConnector\Result;
use Bitrix\ImConnector\Tools\Text;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\ImOpenLines;

class Max extends Base
{
	private const MAX_BOT = 'max';

	public function sendMessageProcessing(array $message, $line): array
	{
		$message['message']['text'] = self::cleanTextOut($message['message']['text']);

		if (isset($message['message']['files']))
		{
			$message['message']['files'] = self::processingFiles($message['message']['files']);
		}

		return parent::sendMessageProcessing($message, $line);
	}

	protected function cleanTextOut(string $text): string
	{
		$parser = new \CTextParser();
		$parser->allow['SMILES'] = 'N';
		$text = $parser->convertText($text);
		$text = str_replace(["<br>", "<br />", "[br]", "#br#", "[BR]", "#BR#"], "\n", $text);
		$text = Text::parseQuoting($text);

		return $text;
	}

	protected function processingFiles(array $files): array
	{
		if (!Loader::includeModule('disk'))
		{
			return $files;
		}

		foreach ($files as $key => $file)
		{
			if (preg_match('/^http.+~.+$/', trim($file['link'])))
			{
				$fileUri = \CBXShortUri::GetUri($file['link']);
				if ($fileUri)
				{
					$parsedUrl = parse_url($fileUri['URI']);
					$queryParams = [];
					parse_str($parsedUrl['query'], $queryParams);
					if (isset($queryParams['FILE_ID']))
					{
						$diskFile = \Bitrix\Disk\File::getById((int)$queryParams['FILE_ID']);
						if ($diskFile)
						{
							$files[$key]['publicUrl'] = $fileUri['URI'];
						}
					}
				}
			}
		}

		return $files;
	}

	public static function sendNotifyMaxAgent(): string
	{
		if (\Bitrix\Main\Config\Option::get('imconnector', 'feature_max', 'Y') !== 'Y')
		{
			return __METHOD__ . '();';
		}

		if (!Loader::includeModule('im'))
		{
			return '';
		}

		$portalRegion = Connector::getPortalRegion();
		if (!Connector::isAllowedConnectorInRegion(Library::ID_MAX_CONNECTOR, $portalRegion))
		{
			return '';
		}

		$statusList = \Bitrix\ImConnector\Status::getInstanceAll();
		if (count($statusList) < 2)
		{
			return __METHOD__ . '();';
		}

		$notificationFields = [
			'NOTIFY_TYPE' => \IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'imconnector',
			'NOTIFY_EVENT' => 'admin_notification',
			'NOTIFY_TITLE' => Loc::getMessage('IMCONNECTOR_MAX_NOTIFY_TITLE'),
			'NOTIFY_MESSAGE' => Loc::getMessage('IMCONNECTOR_MAX_NOTIFY_MESSAGE', [
				'#LINK_START#' => '[URL=https://business.max.ru/self/?utm_source=bitrix]',
				'#LINK_END#' => '[/URL]',
			]),
		];

		$adminIds = self::getAdminIds();
		foreach ($adminIds as $adminId)
		{
			$notificationFields['TO_USER_ID'] = $adminId;
			\CIMNotify::Add($notificationFields);
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

	public function processingInputCommand(string $command, array $message, int $line): Result
	{
		$result = new Result();

		$userResult = $this->processingUser($message['user']);
		if ($userResult->isSuccess())
		{
			$userId = $userResult->getResult();
		}
		else
		{
			return $result->addErrors($userResult->getErrors());
		}

		$chat = $this->getChat([
			'USER_CODE' => $this->generateChatCode($line, (int)$message['user']['id'], (int)$userId),
			'USER_ID' => $userId,
			'CONNECTOR' => $message,
		]);

		$chatId = $chat->getData('ID');
		if (!$chatId)
		{
			return $result->addError(new Error(
				'Failed to create chat',
				'ERROR_IMCONNECTOR_FAILED_CHAT',
				__METHOD__,
				$message
			));
		}

		if ($result->isSuccess())
		{
			$message['user'] = $userId;

			$result->setResult($message);
		}

		return $result;
	}

	private function getChat(array $params): ?ImOpenLines\Chat
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return null;
		}

		$chat = new ImOpenLines\Chat();
		$chat->load($params);

		return $chat;
	}

	private function generateChatCode(int $lineId, int $connectorUserId, int $userId): string
	{
		return implode('|', [self::MAX_BOT, $lineId, $connectorUserId, $userId]);
	}
}
