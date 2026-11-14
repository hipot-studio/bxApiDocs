<?php

namespace Bitrix\ImOpenLines\AutomaticAction\Welcome;

use Bitrix\ImConnector\Status;
use Bitrix\ImOpenLines\Connector;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

/**
 * Reads the settings the network welcome rule depends on.
 * This is the only injection point of the rule: tests replace it via Welcome::setNetworkSettings().
 */
class NetworkSettings
{
	/**
	 * Lines allowed to send the line welcome message regardless of the channel welcome text.
	 *
	 * @return int[]
	 */
	public function getForcedLineIds(): array
	{
		try
		{
			$idList = Json::decode(Option::get('imopenlines', 'network_welcome_allowed_list', '[]'));
		}
		catch (ArgumentException)
		{
			return [];
		}

		if (!is_array($idList))
		{
			return [];
		}

		$result = [];
		foreach ($idList as $id)
		{
			if (is_numeric($id))
			{
				$result[] = (int)$id;
			}
		}

		return $result;
	}

	/**
	 * Local copy of the channel settings.
	 *
	 * @return array|null Null when the channel settings are unknown: the line has no network channel,
	 *                    imconnector is missing and there is nowhere to read them from,
	 *                    or the stored copy is not a readable settings array.
	 */
	public function getChannelStatusData(int $lineId): ?array
	{
		if (!Loader::includeModule('imconnector'))
		{
			return null;
		}

		$status = Status::getInstance(Connector::TYPE_NETWORK, $lineId);
		if ($status->getId() === null)
		{
			return null;
		}

		$data = $status->getData();

		return is_array($data) ? $data : null;
	}
}
