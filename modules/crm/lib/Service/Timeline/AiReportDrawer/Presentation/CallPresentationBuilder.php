<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation;

use Bitrix\Crm\Format\Duration;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\DateTimeFormatter;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\MessageProvider;
use Bitrix\Main\PhoneNumber\Parser;

final class CallPresentationBuilder implements ActivityPresentationBuilderInterface
{
	public function supports(ActivityContext $context): bool
	{
		return !$context->isOpenLineActivity()
			&& ($context->activity['PROVIDER_ID'] ?? null) !== Email::getId()
		;
	}

	public function buildSubtitle(ActivityContext $context): ?array
	{
		$type = match ((int)($context->activity['DIRECTION'] ?? \CCrmActivityDirection::Undefined)) {
			\CCrmActivityDirection::Incoming => 'incoming-call',
			\CCrmActivityDirection::Outgoing => 'outgoing-call',
			default => null,
		};
		if ($type === null)
		{
			return null;
		}

		$client = $this->prepareCallSubtitleClient($context->clientData);
		$responsible = is_array($context->responsibleData) ? $context->responsibleData : null;

		return [
			'type' => $type,
			'data' => [
				'client' => $client,
				'responsible' => is_array($responsible) ? [
					'responsibleId' => (int)($responsible['id'] ?? 0),
					'responsibleName' => (string)($responsible['name'] ?? ''),
					'responsibleAvatarUrl' => (string)($responsible['photoUrl'] ?? ''),
					'responsibleProfileUrl' => (string)($responsible['profileUrl'] ?? ''),
					'rating' => (int)($responsible['rating'] ?? 0),
				] : null,
				'date' => $context->activity['CREATED'] ?? '',
			],
		];
	}

	public function buildInfoPopup(ActivityContext $context): ?array
	{
		$clientData = $context->clientData;
		if ($this->isHiddenClientData($clientData))
		{
			$clientData = null;
		}

		$entity = $this->prepareInfoPopupEntity(
			$context->request->ownerTypeId,
			$context->request->ownerId,
		);
		if ($entity === null)
		{
			return null;
		}

		$callInfo = [];
		$originId = (string)($context->activity['ORIGIN_ID'] ?? '');
		if (VoxImplantManager::isVoxImplantOriginId($originId))
		{
			$callInfo = VoxImplantManager::getCallInfo(
				VoxImplantManager::extractCallIdFromOriginId($originId),
			) ?? [];
		}

		$isIncomingCall = (
			(int)($context->activity['DIRECTION'] ?? \CCrmActivityDirection::Undefined)
			=== \CCrmActivityDirection::Incoming
		);
		$activityId = (int)($context->activity['ID'] ?? 0);
		$portalNumber = (string)($callInfo['PORTAL_LINE']['FULL_NAME'] ?? $callInfo['PORTAL_NUMBER'] ?? '');
		$clientNumber = (string)($callInfo['PHONE_NUMBER'] ?? '');
		if ($clientNumber === '' && $activityId > 0)
		{
			$clientNumber = $this->getActivityCommunicationValue($activityId) ?? '';
		}

		$hasClient = is_array($clientData);
		$hasClientNumber = ($clientNumber !== '');
		$clientPhoneAction = $hasClient && $hasClientNumber
			? [
				'type' => 'phone-call',
				'phoneNumber' => $clientNumber,
				'entityTypeId' => $clientData['clientEntityTypeId'] ?? $context->request->ownerTypeId,
				'entityId' => $clientData['clientId'] ?? $context->request->ownerId,
				'ownerTypeId' => $context->request->ownerTypeId,
				'ownerId' => $context->request->ownerId,
				'activityId' => $activityId > 0 ? $activityId : null,
			]
			: null
		;

		return [
			'type' => 'call',
			'entity' => $entity,
			'isIncomingCall' => $isIncomingCall,
			'hasClient' => $hasClient,
			'dateAndDuration' => $this->prepareInfoPopupDateAndDuration(
				$callInfo['CALL_START_DATE'] ?? ($context->activity['START_TIME'] ?? $context->activity['CREATED'] ?? null),
				isset($callInfo['DURATION']) ? (int)$callInfo['DURATION'] : null,
				$context->currentUserId,
			),
			'fromNumber' => [
				'text' => $isIncomingCall
					? $this->formatPhoneNumberForPopup($clientNumber)
					: $this->formatPhoneNumberForPopup($portalNumber),
				'isAccent' => $isIncomingCall && $hasClient && $hasClientNumber,
				'action' => $isIncomingCall ? $clientPhoneAction : null,
			],
			'toNumber' => [
				'text' => $isIncomingCall
					? $this->formatPhoneNumberForPopup($portalNumber)
					: $this->formatPhoneNumberForPopup($clientNumber),
				'isAccent' => !$isIncomingCall && $hasClient && $hasClientNumber,
				'action' => !$isIncomingCall ? $clientPhoneAction : null,
			],
		];
	}

	private function prepareCallSubtitleClient(?array $clientData): ?array
	{
		if ($this->isHiddenClientData($clientData))
		{
			return [
				'clientName' => $this->getHiddenClientName(),
				'clientId' => null,
				'clientEntityTypeId' => null,
				'clientDetailsUrl' => null,
			];
		}

		if (!is_array($clientData))
		{
			return null;
		}

		$clientEntityTypeId = (int)($clientData['clientEntityTypeId'] ?? 0);
		$clientId = (int)($clientData['clientId'] ?? 0);
		$clientDetailsUrl = trim((string)($clientData['clientDetailsUrl'] ?? ''));

		return [
			'clientName' => (string)(\CCrmOwnerType::GetCaption($clientEntityTypeId, $clientId) ?? ''),
			'clientId' => $clientId > 0 ? $clientId : null,
			'clientEntityTypeId' => $clientEntityTypeId > 0 ? $clientEntityTypeId : null,
			'clientDetailsUrl' => $clientDetailsUrl !== '' ? $clientDetailsUrl : null,
		];
	}

	private function isHiddenClientData(?array $clientData): bool
	{
		return is_array($clientData) && (($clientData['isHidden'] ?? false) === true);
	}

	private function getHiddenClientName(): ?string
	{
		return MessageProvider::getHiddenClientName();
	}

	private function prepareInfoPopupEntity(int $ownerTypeId, int $ownerId): ?array
	{
		if ($ownerTypeId <= 0 || $ownerId <= 0)
		{
			return null;
		}

		$title = (string)(\CCrmOwnerType::GetCaption($ownerTypeId, $ownerId) ?? '');
		if ($title === '')
		{
			return null;
		}

		return [
			'title' => $title,
			'href' => \CCrmOwnerType::GetEntityShowPath($ownerTypeId, $ownerId),
			'ownerTypeId' => $ownerTypeId,
		];
	}

	private function prepareInfoPopupDateAndDuration(mixed $dateTimeValue, ?int $duration, int $currentUserId): string
	{
		$dateTimeText = DateTimeFormatter::format($dateTimeValue, $currentUserId) ?? '';
		$durationText = ($duration !== null && $duration > 0) ? Duration::format($duration) : '';

		return implode(', ', array_filter([$dateTimeText, $durationText]));
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

	private function formatPhoneNumberForPopup(string $phoneNumber): string
	{
		if ($phoneNumber === '')
		{
			return '';
		}

		return Parser::getInstance()?->parse($phoneNumber)->format() ?? $phoneNumber;
	}
}
