<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\OpenLineManager;
use Bitrix\Crm\Service\Broker\User;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\DateTimeFormatter;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ManagerListPreparer;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\MessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ResponsibleDataProvider;

final class OpenLinePresentationBuilder implements ActivityPresentationBuilderInterface
{
	public function __construct(private readonly ResponsibleDataProvider $responsibleDataProvider)
	{
	}

	public function supports(ActivityContext $context): bool
	{
		return $context->isOpenLineActivity();
	}

	public function buildSubtitle(ActivityContext $context): ?array
	{
		$clientData = $context->clientData;
		if (is_array($clientData) && !$this->isHiddenClientData($clientData))
		{
			$clientData['clientName'] = (string)(
				\CCrmOwnerType::GetCaption(
					(int)($clientData['clientEntityTypeId'] ?? 0),
					(int)($clientData['clientId'] ?? 0),
				)
				?? ''
			);
		}

		return [
			'type' => 'open-lines',
			'data' => [
				'client' => $this->prepareOpenLinesSubtitleClient(
					$this->getOpenLinesClientName($context->activity),
					$clientData,
				),
				'managers' => ManagerListPreparer::prepare(
					$this->getOpenLinesManagers($context->activity),
				),
			],
		];
	}

	public function buildInfoPopup(ActivityContext $context): ?array
	{
		$dialogId = $this->getOpenLinesDialogId($context->activity);
		$userCode = (string)($context->activity['PROVIDER_PARAMS']['USER_CODE'] ?? '');

		return [
			'type' => 'open-lines',
			'chat' => [
				'text' => $this->prepareOpenLinesChatTitle($userCode),
				'isAccent' => $dialogId !== null,
				'action' => $dialogId === null ? null : [
					'type' => 'open-lines-chat',
					'value' => $dialogId,
				],
			],
			'startedAt' => $this->prepareOpenLinesDateTime($context->activity['START_TIME'] ?? ($context->activity['CREATED'] ?? null), $context->currentUserId),
			'endedAt' => $this->prepareOpenLinesEndDateTime($context->activity, $context->currentUserId),
			'channel' => $this->prepareOpenLinesChannelName($context->activity, $userCode),
		];
	}

	private function prepareOpenLinesSubtitleClient(string $externalClientName, ?array $crmClient): array
	{
		if ($this->isHiddenClientData($crmClient))
		{
			return [
				'clientName' => $this->getHiddenClientName(),
				'clientId' => null,
				'clientEntityTypeId' => null,
				'clientDetailsUrl' => null,
				'isCrmContact' => false,
			];
		}

		$crmClientName = trim((string)($crmClient['clientName'] ?? ''));
		$clientEntityTypeId = (int)($crmClient['clientEntityTypeId'] ?? 0);
		$isCrmContact = ($clientEntityTypeId === \CCrmOwnerType::Contact && $crmClientName !== '');

		if ($isCrmContact)
		{
			return [
				'clientName' => $crmClientName,
				'clientId' => (int)($crmClient['clientId'] ?? 0),
				'clientEntityTypeId' => $clientEntityTypeId,
				'clientDetailsUrl' => (string)($crmClient['clientDetailsUrl'] ?? ''),
				'isCrmContact' => true,
			];
		}

		$clientName = trim($externalClientName);
		if ($clientName === '')
		{
			$clientName = $crmClientName;
		}

		return [
			'clientName' => $clientName,
			'clientId' => null,
			'clientEntityTypeId' => null,
			'clientDetailsUrl' => null,
			'isCrmContact' => false,
		];
	}

	private function getOpenLinesSessionData(array $activity): array
	{
		$sessionId = (int)($activity['ASSOCIATED_ENTITY_ID'] ?? 0);
		if ($sessionId <= 0)
		{
			return [];
		}

		return OpenLineManager::getSessionData($sessionId);
	}

	private function getOpenLinesClientName(array $activity): string
	{
		$sessionData = $this->getOpenLinesSessionData($activity);
		$userId = (int)($sessionData['USER_ID'] ?? 0);
		$userData = $userId > 0 ? (new User())->getById($userId) : null;

		if (!is_array($userData))
		{
			return $this->getOpenLinesGuestName();
		}

		$nameParts = [
			trim((string)($userData['NAME'] ?? '')),
			trim((string)($userData['LAST_NAME'] ?? '')),
			trim((string)($userData['SECOND_NAME'] ?? '')),
		];
		$hasUserName = !empty(array_filter(
			$nameParts,
			static fn(string $value): bool => $value !== '',
		));
		if (!$hasUserName)
		{
			return $this->getOpenLinesGuestName();
		}

		$formattedName = trim((string)($userData['FORMATTED_NAME'] ?? ''));

		return $formattedName !== '' ? $formattedName : $this->getOpenLinesGuestName();
	}

	private function getOpenLinesManagers(array $activity): array
	{
		$sessionData = $this->getOpenLinesSessionData($activity);
		$sessionId = (int)($sessionData['ID'] ?? $activity['ASSOCIATED_ENTITY_ID'] ?? 0);
		$managers = [];

		$transfers = OpenLineManager::getSessionOperatorTransfers($sessionId);
		foreach ($transfers as $transfer)
		{
			$workedAtTs = $this->extractTimestamp($transfer['DATE_CREATE'] ?? null);
			$this->addOpenLinesManager($managers, (int)($transfer['USER_ID'] ?? 0), $workedAtTs);

			if (($transfer['TRANSFER_TYPE'] ?? null) === 'USER')
			{
				$this->addOpenLinesManager($managers, (int)($transfer['TRANSFER_USER_ID'] ?? 0), $workedAtTs);
			}
		}

		foreach ($this->getOpenLinesChatManagerIds($sessionData) as $managerId)
		{
			$this->addOpenLinesManager($managers, $managerId, null);
		}

		if (empty($managers))
		{
			$this->addOpenLinesCurrentManager($managers, $activity, $sessionData);
		}

		return array_values($managers);
	}

	private function getOpenLinesChatManagerIds(array $sessionData): array
	{
		$chatId = (int)($sessionData['CHAT_ID'] ?? 0);
		$guestUserId = (int)($sessionData['USER_ID'] ?? 0);

		return \Bitrix\Crm\Integration\Im\Chat::getActiveMemberIds($chatId, [$guestUserId]);
	}

	private function addOpenLinesCurrentManager(array &$managers, array $activity, array $sessionData): void
	{
		$managerId = (int)($sessionData['OPERATOR_ID'] ?? 0);
		if ($managerId <= 0)
		{
			$managerId = (int)($activity['RESPONSIBLE_ID'] ?? 0);
		}

		$this->addOpenLinesManager($managers, $managerId, null);
	}

	private function addOpenLinesManager(array &$managers, int $managerId, ?int $workedAtTs): void
	{
		if ($managerId <= 0)
		{
			return;
		}

		$workedAtTs ??= 0;

		if (
			isset($managers[$managerId])
			&& (int)($managers[$managerId]['workedAtTs'] ?? 0) >= $workedAtTs
		)
		{
			return;
		}

		$responsible = $this->responsibleDataProvider->getById($managerId);
		if (!is_array($responsible))
		{
			return;
		}

		$managers[$managerId] = [
			'responsibleId' => $managerId,
			'responsibleName' => (string)($responsible['name'] ?? ''),
			'responsibleAvatarUrl' => (string)($responsible['photoUrl'] ?? ''),
			'responsibleProfileUrl' => (string)($responsible['profileUrl'] ?? ''),
			'rating' => (int)($responsible['rating'] ?? 0),
			'workedAtTs' => $workedAtTs,
		];
	}

	private function extractTimestamp(mixed $value): ?int
	{
		if ($value instanceof \Bitrix\Main\Type\DateTime)
		{
			return $value->getTimestamp();
		}

		if (!is_string($value) || $value === '')
		{
			return null;
		}

		try
		{
			return (new \Bitrix\Main\Type\DateTime($value, \Bitrix\Main\Type\DateTime::getFormat()))
				->getTimestamp()
			;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function getOpenLinesGuestName(): ?string
	{
		return MessageProvider::getOpenLinesGuestName();
	}

	private function getHiddenClientName(): ?string
	{
		return MessageProvider::getHiddenClientName();
	}

	private function isHiddenClientData(?array $clientData): bool
	{
		return is_array($clientData) && (($clientData['isHidden'] ?? false) === true);
	}

	private function prepareOpenLinesDateTime(mixed $dateTimeValue, int $currentUserId): string
	{
		return DateTimeFormatter::format($dateTimeValue, $currentUserId) ?? '-';
	}

	private function prepareOpenLinesEndDateTime(array $activity, int $currentUserId): string
	{
		return DateTimeFormatter::format($activity['END_TIME'] ?? null, $currentUserId) ?? '-';
	}

	private function prepareOpenLinesChatTitle(string $userCode): string
	{
		return OpenLineManager::getChatTitle($userCode) ?? OpenLine::getChatName($userCode);
	}

	private function prepareOpenLinesChannelName(array $activity, string $userCode): string
	{
		$provider = \CCrmActivity::GetProviderById((string)($activity['PROVIDER_ID'] ?? ''));
		$sourceList = $provider ? $provider::getResultSources() : [];
		$connectorType = OpenLineManager::getLineConnectorType($userCode);
		if (!$connectorType || empty($sourceList))
		{
			return '-';
		}

		return $sourceList[$connectorType] ?? ($sourceList['livechat'] ?? '-');
	}

	private function getOpenLinesDialogId(array $activity): ?string
	{
		$activityId = (int)($activity['ID'] ?? 0);
		$dialogId = $activityId > 0 ? (string)($this->getActivityCommunicationValue($activityId) ?? '') : '';

		return $dialogId !== '' ? $dialogId : null;
	}

	private function getActivityCommunicationInfo(int $activityId): ?array
	{
		$communications = \CCrmActivity::PrepareCommunicationInfos([$activityId]);
		$communication = $communications[$activityId] ?? null;

		return is_array($communication) ? $communication : null;
	}

	private function getActivityCommunicationValue(int $activityId): ?string
	{
		$communication = $this->getActivityCommunicationInfo($activityId);
		$value = (string)($communication['VALUE'] ?? '');

		return $value !== '' ? $value : null;
	}
}
