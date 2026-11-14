<?php

namespace Bitrix\ImOpenLines\V2\Chat;

use Bitrix\Im\V2\Chat as BaseChat;
use Bitrix\Im\V2\Chat\OpenLineChat;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\SilentMode\PersonalSilentMode;
use Bitrix\ImOpenLines\V2\Line\LineSettings;
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
					'silentMode' => $this->isSilentModeForCurrentUser(),
					'dateCreate' => $this->data->getDateCreate(),
					'multidialog' => $this->isMultidialog(),
				],
				'lineSettings' => $this->getLineSettings(),
			],
		];
	}

	private function getLineSettings(): ?array
	{
		$lineId = $this->resolveLineId();
		if ($lineId <= 0)
		{
			return null;
		}

		return LineSettings::createByLineId($lineId)->toRestFormat();
	}

	/**
	 * The session line wins over the chat entity data: on transfer with a line change
	 * the session is the source of truth. The client resolves the line the same way.
	 */
	private function resolveLineId(): int
	{
		$lineId = $this->resolveSessionLineId();

		if ($lineId <= 0)
		{
			$lineId = (int)$this->data->getLineId();
		}

		return $lineId;
	}

	/**
	 * Narrow read by the session id from the entity data: a full session load would hydrate
	 * the whole wide row for a single field. Without the session id the chat resolves the
	 * session on its own. Not cached: CONFIG_ID changes on transfer.
	 */
	private function resolveSessionLineId(): int
	{
		if (!$this->chat instanceof OpenLineChat)
		{
			return 0;
		}

		$sessionId = (int)$this->data->getSessionId();
		if ($sessionId <= 0)
		{
			return (int)($this->chat->getSession()?->getConfigId() ?? 0);
		}

		$session = SessionTable::getByPrimary($sessionId, ['select' => ['CONFIG_ID']])->fetch();

		return is_array($session) ? (int)$session['CONFIG_ID'] : 0;
	}

	/**
	 * Personal hidden messages mode for the current user of the response.
	 * The currentSession.silentMode REST field shape is unchanged - no frontend changes.
	 */
	private function isSilentModeForCurrentUser(): bool
	{
		$userId = (int)$this->chat->getContext()->getUserId();
		$chatId = (int)$this->chat->getId();

		return PersonalSilentMode::isEnabled($userId, $chatId);
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
