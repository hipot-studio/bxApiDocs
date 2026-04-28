<?php

namespace Bitrix\ImOpenLines\V2\Chat;

use Bitrix\Im\V2\Chat as BaseChat;
use Bitrix\Main\Loader;

class OpenLineChatDecorator
{
	private BaseChat $chat;
	private OpenLineEntityData $data;

	public function __construct(BaseChat $chat)
	{
		$this->chat = $chat;

		$raw = $this->chat->getEntityData();
		$this->data = new OpenLineEntityData($raw);
	}

	public function toRestFormat(): array
	{
		return [
			'openlines' => [
				'connector' => [
					'connectorId' => $this->data->getConnectorId(),
					'lineId' => $this->data->getLineId(),
					'connectorChatId' => $this->data->getConnectorChatId(),
					'connectorUserId' => $this->data->getConnectorUserId(),
				],
				'crm' => [
					'crmEnabled' => $this->data->isCrmEnabled(),
					'crmEntityType' => $this->data->getCrmEntityType(),
					'crmEntityId' => $this->data->getCrmEntityId(),
					'leadId' => $this->data->getLeadId(),
					'companyId' => $this->data->getCompanyId(),
					'contactId' => $this->data->getContactId(),
					'dealId' => $this->data->getDealId(),
				],
				'currentSession' => [
					'sessionId' => $this->data->getSessionId(),
					'pause' => $this->data->isPaused(),
					'waitAction' => $this->data->isWaitingForAction(),
					'blockDate' => $this->data->getBlockDate(),
					'blockReason' => $this->data->getBlockReason(),
					'silentMode' => $this->data->isSilentMode(),
					'dateCreate' => $this->data->getDateCreate(),
					'multidialog' => $this->isMultidialog(),
				],
			],
		];
	}

	private function isMultidialog(): bool
	{
		static $cache = [];

		$lineId = $this->data->getLineId();
		if ($lineId === null || $lineId <= 0)
		{
			return false;
		}

		if (isset($cache[$lineId]))
		{
			return $cache[$lineId];
		}

		if (!Loader::includeModule('imconnector'))
		{
			$cache[$lineId] = false;
			return false;
		}

		$connectorStatus = \Bitrix\ImConnector\Status::getInstance(\Bitrix\ImOpenLines\Connector::TYPE_NETWORK, $lineId);
		if (!$connectorStatus->isStatus())
		{
			$cache[$lineId] = false;
			return false;
		}

		$connectorData = $connectorStatus->getData();
		$isMultidialog = isset($connectorData['MULTIDIALOG']) && $connectorData['MULTIDIALOG'] === 'Y';

		$cache[$lineId] = $isMultidialog;
		return $isMultidialog;
	}
}
