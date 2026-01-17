<?php

namespace Bitrix\Crm\Activity\Provider;

use Bitrix\Call\Error;
use Bitrix\Crm\Activity\CommunicationStatistics;
use Bitrix\Crm\Activity\StatisticsMark;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\OpenLineManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Communication\Channel\Event\ChannelEventRegistrar;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Crm\Timeline\LogMessageEntry;
use Bitrix\Crm\Timeline\LogMessageType;
use Bitrix\Main;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use CCrmActivity;
use CCrmActivityNotifyType;
use CCrmActivityType;
use CCrmContentType;
use CCrmOwnerType;
use CTextParser;

Loc::loadMessages(__FILE__);

class OpenLine extends Base implements EventRegistrarInterface
{
	public const ACTIVITY_PROVIDER_ID = 'IMOPENLINES_SESSION';

	public const CHAT_MESSAGE_COPILOT_PROCESSING_LIMIT = 1000;

	public static $isActive;
	public static int $activeLine = 0;

	public static function getId()
	{
		return static::ACTIVITY_PROVIDER_ID;
	}

	public static function getName()
	{
		return Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_NAME');
	}

	public static function isActive()
	{
		if (is_null(static::$isActive))
		{
			$active = Main\Loader::includeModule('imopenlines');
			if ($active)
			{
				static::$activeLine = \Bitrix\ImOpenLines\Model\ConfigTable::getCount(array('=TEMPORARY' => 'N'));
				$active = static::$activeLine > 0;
			}
			static::$isActive = $active;
		}

		return static::$isActive;
	}

	public static function getStatusAnchor()
	{
		if (static::isActive())
		{
			$text = Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_ACTIVE', ['#NUMBER#' => static::$activeLine]);
		}
		else
		{
			$text = Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_INACTIVE');
		}

		return [
			'TEXT' => $text,
			'URL' => Container::getInstance()->getRouter()->getContactCenterUrl(),
		];
	}

	/**
	 * @param array $activity Activity data.
	 * @return bool
	 */
	public static function checkForWaitingCompletion(array $activity)
	{
		return !(isset($activity['COMPLETED']) && $activity['COMPLETED'] === 'Y')
			|| isset($activity['DIRECTION']) && $activity['DIRECTION'] == \CCrmActivityDirection::Incoming;
	}

	/**
	 * Returns supported provider's types
	 *
	 * @return array
	 *
	 * @throws LoaderException
	 */
	public static function getTypes()
	{
		$types = array();
		if (!Main\Loader::includeModule('imopenlines'))
		{
			return $types;
		}

		$orm = \Bitrix\ImOpenLines\Model\ConfigTable::getList(Array(
			'filter' => Array(
				'=TEMPORARY' => 'N'
			)
		));
		while ($config = $orm->fetch())
		{
			$types[] = array(
				'NAME' => Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_TYPE_TEMPLATE', Array('#NAME#' => $config['LINE_NAME'])),
				'PROVIDER_ID' => static::ACTIVITY_PROVIDER_ID,
				'PROVIDER_TYPE_ID' => $config['ID'],
				'DIRECTIONS' => array(
					\CCrmActivityDirection::Incoming => Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_SESSION_INCOMING'),
					\CCrmActivityDirection::Outgoing => Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_SESSION_OUTGOING'),
				),
			);
		}

		return $types;
	}

	/**
	 * @return array
	 */
	public static function getTypesFilterPresets()
	{
		return array(
			array(
				'NAME' => Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_SESSION_INCOMING'),
				'DIRECTION' => \CCrmActivityDirection::Incoming
			),
			array(
				'NAME' => Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_SESSION_OUTGOING'),
				'DIRECTION' => \CCrmActivityDirection::Outgoing
			)
		);
	}

	public static function getCommunicationType($providerTypeId = null)
	{
		return static::COMMUNICATION_TYPE_UNDEFINED;
	}

	/**
	 * @param null|string $providerTypeId Provider type id.
	 * @param int $direction Activity direction.
	 * @return bool
	 */
	public static function isTypeEditable($providerTypeId = null, $direction = \CCrmActivityDirection::Undefined)
	{
		return false;
	}

	/**
	 * @inheritdoc
	 */
	public static function renderView(array $activity)
	{
		if (!Main\ModuleManager::isModuleInstalled('imopenlines'))
		{
			return '';
		}

		$closeSliderOnClick = '';
		if (isset($activity['__template']) && $activity['__template'] === 'slider')
		{
			$closeSliderOnClick = 'if (top.BX.Bitrix24 && top.BX.Bitrix24.Slider) {top.BX.Bitrix24.Slider.closeAll();};';
		}

		return '<div class="crm-task-list-chat">
			<div class="crm-task-list-chat-inner">
				<div class="webform-small-button webform-small-button-blue crm-task-list-chat-button" onclick="top.BXIM.openHistory(\'imol|'.$activity['ASSOCIATED_ENTITY_ID'].'\');'.$closeSliderOnClick.'">'.Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_SESSION_RENDER_VIEW').'</div>
				<div class="webform-small-button webform-small-button-green crm-task-list-chat-button" onclick="top.BXIM.openMessengerSlider(\'imol|'.$activity['PROVIDER_PARAMS']['USER_CODE'].'\', {RECENT: \'N\', MENU: \'N\'})">'.Loc::getMessage('IMOPENLINES_ACTIVITY_PROVIDER_SESSION_RENDER_START').'</div>
			</div>
		</div>';
	}

	public static function getSupportedCommunicationStatistics()
	{
		return array(
			CommunicationStatistics::STATISTICS_QUANTITY,
			CommunicationStatistics::STATISTICS_STATUSES,
			CommunicationStatistics::STATISTICS_MARKS
		);
	}

	public static function getResultSources()
	{
		if (!Main\Loader::includeModule('imconnector'))
		{
			return [];
		}

		return \Bitrix\ImConnector\Connector::getListConnector();
	}

	/**
	 * @inheritdoc
	 */
	public static function checkFields($action, &$fields, $id, $params = null)
	{
		$result = new Main\Result();

		//Only END TIME can be taken for DEADLINE!
		if (isset($fields['END_TIME']) && $fields['END_TIME'] !== '')
		{
			$fields['DEADLINE'] = $fields['END_TIME'];
		}
		return $result;
	}

	public static function onAfterAdd($activityFields, array $params = null)
	{
		if (
			$activityFields['DIRECTION'] === \CCrmActivityDirection::Incoming
			&& isset($activityFields['PROVIDER_PARAMS']['USER_CODE'])
			&& $activityFields['ID'] > 0
		)
		{
			$logMessageId = LogMessageEntry::detectIdByParams(
				$activityFields['PROVIDER_PARAMS']['USER_CODE'],
				LogMessageType::OPEN_LINE_INCOMING,
			);
			if (isset($logMessageId))
			{
				TimelineTable::update($logMessageId, [
					'ASSOCIATED_ENTITY_TYPE_ID' => CCrmOwnerType::Activity,
					'ASSOCIATED_ENTITY_ID' => $activityFields['ID'],
				]);
			}
		}
	}

	public static function onAfterUpdate(
		int $id,
		array $changedFields,
		array $oldFields,
		array $newFields,
		array $params = null
	): void
	{
		$prevIsCompleted = ($oldFields['COMPLETED'] ?? '') === 'Y';
		$curIsCompleted = ($newFields['COMPLETED'] ?? '')  === 'Y';
		$isCompleted = !$prevIsCompleted && $curIsCompleted;
		if ($isCompleted)
		{
			\Bitrix\Crm\Integration\AI\EventHandler::onAfterOpenLineActivityComplete($changedFields, $newFields);
		}
	}

	public static function onBeforeComplete(int $id, array $activityFields, array $params = null)
	{
		if (
			isset($activityFields['COMPLETED'])
			&& $activityFields['COMPLETED'] === 'N'
			&& !empty($activityFields['PROVIDER_PARAMS']['USER_CODE'])
		)
		{
			OpenLineManager::closeDialog($activityFields['PROVIDER_PARAMS']['USER_CODE'], $params['CURRENT_USER'] ?? 0);
		}
	}

	/**
	 * @inheritdoc
	 */
	public static function syncBadges(int $activityId, array $activityFields, array $bindings): void
	{
		$badge = Container::getInstance()->getBadge(
			Badge\Type\OpenLineStatus::OPENLINE_STATUS_TYPE,
			Badge\Type\OpenLineStatus::CHAT_NOT_READ_VALUE,
		);

		$sourceIdentifier = new Badge\SourceIdentifier(
			Badge\SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
			CCrmOwnerType::Activity,
			$activityId,
		);

		$userCode = $activityFields['PROVIDER_PARAMS']['USER_CODE'] ?? null;
		$responsibleId = $activityFields['RESPONSIBLE_ID'] ?? null;
		$isNotReadChat = OpenLineManager::getChatUnReadMessagesCount($userCode, $responsibleId) > 0;
		if ($isNotReadChat)
		{
			foreach ($bindings as $singleBinding)
			{
				$itemIdentifier = new ItemIdentifier((int)$singleBinding['OWNER_TYPE_ID'], (int)$singleBinding['OWNER_ID']);
				$badge->bind($itemIdentifier, $sourceIdentifier);
			}
		}
		else
		{
			foreach ($bindings as $singleBinding)
			{
				$itemIdentifier = new ItemIdentifier((int)$singleBinding['OWNER_TYPE_ID'], (int)$singleBinding['OWNER_ID']);
				$badge->unbind($itemIdentifier, $sourceIdentifier);
			}
		}
	}

	public static function hasPlanner(array $activity): bool
	{
		return false;
	}

	public static function getMoveBindingsLogMessageType(): ?string
	{
		return LogMessageType::OPEN_LINE_MOVED;
	}

	public static function getMessagesForCopilot(int $activityId, int $limit = 100): string
	{
		static $messagesForCopilot = [];

		if (isset($messagesForCopilot[$activityId]))
		{
			return $messagesForCopilot[$activityId];
		}

		$messagesForCopilot[$activityId] = '';

		if ($activityId <= 0)
		{
			return $messagesForCopilot[$activityId];
		}

		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!is_array($activity))
		{
			return $messagesForCopilot[$activityId];
		}

		$userCode = (string)($activity['PROVIDER_PARAMS']['USER_CODE'] ?? '');
		if ($userCode === '')
		{
			return $messagesForCopilot[$activityId];
		}

		$data = OpenLineManager::getMessageData($userCode, $limit);
		if (
			empty($data)
			|| empty($data['messages'])
			|| !is_array($data['messages'])
		)
		{
			return $messagesForCopilot[$activityId];
		}

		$messages = array_filter(
			$data['messages'],
			static fn($item) => is_array($item) && (int)($item['author_id'] ?? 0) > 0
		);
		if (empty($messages))
		{
			return $messagesForCopilot[$activityId];
		}

		$userMap = array_column($data['users'] ?? [], 'name', 'id');

		$result = [];
		foreach ($messages as $message)
		{
			$author = $userMap[$message['author_id']] ?? '';
			$datePart = isset($message['date']) ? ' [' . $message['date'] . ']:' : '';
			$textPart = isset($message['text']) ? trim($message['text']) : '';
			$result[] = sprintf("%s%s\n%s", $author, $datePart, $textPart);
		}

		$result = array_reverse($result);

		$text = trim(implode(' ', $result));
		$text = TextHelper::cleanTextByType($text, CCrmContentType::BBCode);
		$text = preg_replace('/\s+/', ' ', $text);

		$messagesForCopilot[$activityId] = CTextParser::cleanTag(trim($text));

		return $messagesForCopilot[$activityId];
	}

	public static function isCopilotProcessingAvailable(int $activityId, string $messages = ''): bool
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!is_array($activity))
		{
			return false;
		}

		if (empty($messages))
		{
			$messages = self::getMessagesForCopilot($activityId);
		}

		$currentVolume = mb_strlen($messages, 'UTF-8');
		$lastVolume = $activity['SETTINGS']['LAST_MESSAGES_VOLUME'] ?? 0;

		return $currentVolume >= $lastVolume + self::CHAT_MESSAGE_COPILOT_PROCESSING_LIMIT;
	}

	public static function getChatName(string $userCode): string
	{
		$provider = CCrmActivity::GetProviderById(self::getId());
		$sourceList = $provider::getResultSources();
		$connectorType = OpenLineManager::getLineConnectorType($userCode);

		return sprintf(
			'%s "%s"',
			$sourceList[$connectorType] ?? $sourceList['livechat'],
			OpenLineManager::getChatTitle($userCode)
		);
	}

	public function createActivityFromChannelEvent(ChannelEventRegistrar $eventRegistrar): Main\Result
	{
		$result = new Main\Result();

		if (!Main\Loader::includeModule('imopenlines'))
		{
			return $result->addError(new Error('Module imopenlines is not installed'));
		}

		$fields = [
			'TYPE_ID' => CCrmActivityType::Provider,
			'PROVIDER_ID' => self::getId(),
			'NOTIFY_TYPE' => CCrmActivityNotifyType::None,
			'RESULT_MARK' => StatisticsMark::None,
			'BINDINGS' => [],

			'COMPLETED' => 'N',
			'DIRECTION' => \CCrmActivityDirection::Incoming,
			'RESULT_STATUS' => \Bitrix\Crm\Activity\StatisticsStatus::Unanswered,
		];

		$resultItemsCollection = $eventRegistrar->getResultItems();
		if ($resultItemsCollection->count() === 0)
		{
			return $result->addError(new Main\Error('Bindings not found'));
		}

		foreach ($resultItemsCollection as $resultItem)
		{
			$fields['BINDINGS'][] = [
				'OWNER_TYPE_ID' => $resultItem->getEntityTypeId(),
				'OWNER_ID' => $resultItem->getEntityId(),
			];
		}

		$sessionId = null;
		$channelEventPropertiesCollection = $eventRegistrar->getPropertiesCollection();
		if ($channelEventPropertiesCollection->has('SESSION_ID'))
		{
			$sessionId = $channelEventPropertiesCollection->getByCode('SESSION_ID')?->getValue();
		}

		if (empty($sessionId))
		{
			return $result->addError(new Main\Error('SESSION_ID not found'));
		}

//		// @todo need api to get array of activity fields by $sessionId
//		$fields['LINE_ID'] =
//		$fields['USER_CODE'] =
//		$fields['NAME'] =
//		$fields['ASSOCIATED_ENTITY_ID'] =
//		$fields['ORIGIN_ID'] =
//		$fields['COMPLETED'] =
//		$fields['DIRECTION'] =
//		$fields['RESULT_STATUS'] =
//		$fields['SETTINGS'] =
//		$fields['AUTHOR_ID'] =
//		$fields['RESPONSIBLE_ID'] =
//		$fields['USER_CODE'] =
//		$fields['CONNECTOR_ID'] =
//		$fields['COMMUNICATIONS'] =
//		$fields['IS_INCOMING_CHANNEL'] =

		$options = [
			'REGISTER_SONET_EVENT' => true,
		];

		$lineId = $fields['LINE_ID'] ?? null;

		return $this->createActivity($lineId, $fields, $options);
	}
}
