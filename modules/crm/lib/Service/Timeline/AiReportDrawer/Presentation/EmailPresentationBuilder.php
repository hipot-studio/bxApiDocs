<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation;

use Bitrix\Crm\Activity\Email\MetaParser;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\FieldMultiTable;
use Bitrix\Crm\Integration\Mail\MailboxUserProvider;
use Bitrix\Crm\Service\Broker\User;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\DateTimeFormatter;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\EmailThreadActivityProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ManagerListPreparer;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\MessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SummaryDataProvider;
use Bitrix\Main\Type\DateTime;

final class EmailPresentationBuilder implements ActivityPresentationBuilderInterface
{
	private array $cache = [];

	public function __construct(
		private readonly SummaryDataProvider $summaryDataProvider,
		private readonly EmailThreadActivityProvider $emailThreadActivityProvider,
	)
	{
	}

	public function supports(ActivityContext $context): bool
	{
		return ($context->activity['PROVIDER_ID'] ?? null) === Email::getId();
	}

	public function buildSubtitle(ActivityContext $context): ?array
	{
		$data = $this->getData($context);

		return [
			'type' => 'email',
			'data' => [
				'client' => $data['client'],
				'managers' => $data['managers'],
			],
		];
	}

	public function buildInfoPopup(ActivityContext $context): ?array
	{
		$data = $this->getData($context);
		$activityId = (int)($context->activity['ID'] ?? 0);

		return [
			'type' => 'email',
			'entity' => $data['entity'],
			'conversationStartedAt' => $data['conversationStartedAt'],
			'lastAnalyzedAt' => $data['lastAnalyzedAt'],
			'managers' => $data['managers'],
			'threadContext' => 'current',
			'activity' => [
				'action' => $activityId > 0 ? [
					'type' => 'email-activity',
					'value' => $activityId,
				] : null,
			],
		];
	}

	private function getData(ActivityContext $context): array
	{
		$key = implode(':', [
			$context->request->activityId,
			$context->request->ownerTypeId,
			$context->request->ownerId,
			$context->currentUserId,
		]);
		if (isset($this->cache[$key]))
		{
			return $this->cache[$key];
		}

		$activities = $this->emailThreadActivityProvider->getByActivityId(
			$context->request->activityId,
			$context->request->ownerTypeId,
			$context->request->ownerId,
		);
		$participants = $this->extractParticipants($activities);
		$usersByEmail = $this->loadUsersByEmail(array_column($participants, 'email'));

		return $this->cache[$key] = [
			'client' => $this->prepareClient($participants, $usersByEmail, $context),
			'managers' => $this->prepareManagers($participants, $usersByEmail),
			'entity' => $this->prepareEntity($context),
			'conversationStartedAt' => DateTimeFormatter::format(
				$this->loadConversationStartedAt($context),
				$context->currentUserId,
			),
			'lastAnalyzedAt' => DateTimeFormatter::format(
				$this->loadLastAnalyzedAt($context),
				$context->currentUserId,
			),
		];
	}

	private function extractParticipants(array $activities): array
	{
		$participants = [];
		foreach ($activities as $activity)
		{
			$meta = MetaParser::fromActivityFields($activity);
			$workedAtTs = $this->extractTimestamp($activity['START_TIME'] ?? null) ?? 0;
			foreach ([$meta->getFromList(), $meta->getTo(), $meta->getCc(), $meta->getBcc()] as $addresses)
			{
				foreach ($addresses as $email)
				{
					$participants[] = [
						'email' => $email,
						'workedAtTs' => $workedAtTs,
					];
				}
			}
		}

		return $participants;
	}

	private function loadUsersByEmail(array $emails): array
	{
		$emails = array_values(array_unique(array_filter($emails)));
		if (empty($emails))
		{
			return [];
		}

		$userBroker = new User();
		$userIdsByEmail = $userBroker->getActiveRealUserIdsByEmails($emails);
		foreach (MailboxUserProvider::getUserIdsByEmails($emails) as $email => $ids)
		{
			foreach ($ids as $userId)
			{
				$userIdsByEmail[$email][$userId] = $userId;
			}
		}

		$userIds = [];
		foreach ($userIdsByEmail as $ids)
		{
			if (count($ids) === 1)
			{
				$userIds[] = (int)reset($ids);
			}
		}
		$users = $userBroker->getBunchByIds($userIds);
		$result = [];
		foreach ($userIdsByEmail as $email => $ids)
		{
			if (count($ids) !== 1)
			{
				continue;
			}
			$userId = (int)reset($ids);
			if (is_array($users[$userId] ?? null))
			{
				$result[$email] = $users[$userId];
			}
		}

		return $result;
	}

	private function prepareManagers(array $participants, array $usersByEmail): array
	{
		$managers = [];
		foreach ($participants as $participant)
		{
			$user = $usersByEmail[$participant['email']] ?? null;
			if (!is_array($user))
			{
				continue;
			}
			$managers[] = [
				'responsibleId' => (int)($user['ID'] ?? 0),
				'responsibleName' => (string)($user['FORMATTED_NAME'] ?? ''),
				'responsibleAvatarUrl' => (string)($user['PHOTO_URL'] ?? ''),
				'responsibleProfileUrl' => (string)($user['SHOW_URL'] ?? ''),
				'workedAtTs' => (int)$participant['workedAtTs'],
			];
		}

		return ManagerListPreparer::prepare($managers);
	}

	private function prepareClient(array $participants, array $usersByEmail, ActivityContext $context): ?array
	{
		$emails = array_values(array_unique(array_column($participants, 'email')));
		if (empty($emails))
		{
			return null;
		}
		$rows = FieldMultiTable::getList([
			'select' => ['ENTITY_ID', 'ELEMENT_ID', 'VALUE'],
			'filter' => [
				'=TYPE_ID' => \CCrmFieldMulti::EMAIL,
				'@ENTITY_ID' => [\CCrmOwnerType::ContactName, \CCrmOwnerType::CompanyName],
				'@VALUE' => $emails,
			],
		])->fetchAll();
		$candidates = [];
		foreach ($rows as $row)
		{
			$email = MetaParser::parseEmailList((string)($row['VALUE'] ?? ''))[0] ?? '';
			$typeId = \CCrmOwnerType::ResolveID((string)($row['ENTITY_ID'] ?? ''));
			$entityId = (int)($row['ELEMENT_ID'] ?? 0);
			if ($email !== '' && $typeId > 0 && $entityId > 0)
			{
				$candidates[$email][$typeId][$entityId] = $entityId;
			}
		}
		$itemPermissions = $context->currentUserId > 0
			? Container::getInstance()->getUserPermissions($context->currentUserId)->item()
			: Container::getInstance()->getUserPermissions()->item()
		;
		$entityIdsByType = [];
		foreach ($candidates as $email => $candidatesByType)
		{
			if (isset($usersByEmail[$email]))
			{
				continue;
			}
			foreach ($candidatesByType as $typeId => $entityIds)
			{
				foreach ($entityIds as $entityId)
				{
					$entityIdsByType[$typeId][$entityId] = $entityId;
				}
			}
		}
		foreach ($entityIdsByType as $typeId => $entityIds)
		{
			$itemPermissions->preloadPermissionAttributes($typeId, array_values($entityIds));
		}

		$hasHiddenClient = false;
		$hasAmbiguousClient = false;
		foreach ([\CCrmOwnerType::Contact, \CCrmOwnerType::Company] as $typeId)
		{
			foreach ($participants as $participant)
			{
				$email = $participant['email'];
				if (isset($usersByEmail[$email]))
				{
					continue;
				}
				$matching = $candidates[$email][$typeId] ?? [];
				$readable = array_values(array_filter(
					$matching,
					fn(int $entityId): bool => $itemPermissions->canRead($typeId, $entityId),
				));
				if (count($readable) === 1)
				{
					return $this->prepareCrmClient([
						'clientEntityTypeId' => $typeId,
						'clientId' => $readable[0],
						'clientDetailsUrl' => \CCrmOwnerType::GetEntityShowPath($typeId, $readable[0]),
					]);
				}
				if (count($matching) > 1)
				{
					$hasAmbiguousClient = true;
				}
				elseif (count($matching) === 1 && empty($readable))
				{
					$hasHiddenClient = true;
				}
			}
		}

		if ($hasHiddenClient && !$hasAmbiguousClient)
		{
			return [
				'clientName' => MessageProvider::getHiddenClientName(),
				'clientId' => null,
				'clientEntityTypeId' => null,
				'clientDetailsUrl' => null,
				'isCrmContact' => false,
			];
		}

		return null;
	}

	private function prepareCrmClient(?array $clientData): ?array
	{
		if (!is_array($clientData) || (($clientData['isHidden'] ?? false) === true))
		{
			return null;
		}

		$clientEntityTypeId = (int)($clientData['clientEntityTypeId'] ?? 0);
		$clientId = (int)($clientData['clientId'] ?? 0);
		if ($clientEntityTypeId <= 0 || $clientId <= 0)
		{
			return null;
		}

		$clientName = trim((string)($clientData['clientName'] ?? ''));
		if ($clientName === '')
		{
			$clientName = trim((string)(\CCrmOwnerType::GetCaption($clientEntityTypeId, $clientId) ?? ''));
		}

		return [
			'clientName' => $clientName,
			'clientId' => $clientId,
			'clientEntityTypeId' => $clientEntityTypeId,
			'clientDetailsUrl' => (string)($clientData['clientDetailsUrl'] ?? ''),
			'isCrmContact' => ($clientEntityTypeId === \CCrmOwnerType::Contact && $clientName !== ''),
		];
	}

	private function prepareEntity(ActivityContext $context): ?array
	{
		$typeId = (int)($context->activity['OWNER_TYPE_ID'] ?? 0);
		$entityId = (int)($context->activity['OWNER_ID'] ?? 0);
		if ($typeId <= 0 || $entityId <= 0)
		{
			return null;
		}
		if (!$this->canRead($typeId, $entityId, $context->currentUserId))
		{
			$factory = Container::getInstance()->getFactory($typeId);
			if ($factory === null)
			{
				return null;
			}
			$dataClass = $factory->getDataClass();
			$entity = $dataClass::query()
				->setSelect(['ID'])
				->where('ID', $entityId)
				->fetch()
			;
			if (!$entity)
			{
				return null;
			}
			$messages = Container::getInstance()->getLocalization()->loadMessages();

			return [
				'title' => $messages['CRM_COMMON_HIDDEN_ITEM'] ?? '',
				'href' => null,
				'ownerTypeId' => 0,
			];
		}
		$title = trim((string)(\CCrmOwnerType::GetCaption($typeId, $entityId) ?? ''));
		if ($title === '')
		{
			return null;
		}

		return [
			'title' => $title,
			'href' => \CCrmOwnerType::GetEntityShowPath($typeId, $entityId),
			'ownerTypeId' => $typeId,
		];
	}

	private function canRead(int $typeId, int $entityId, int $userId): bool
	{
		$permissions = $userId > 0
			? Container::getInstance()->getUserPermissions($userId)
			: Container::getInstance()->getUserPermissions()
		;

		return $permissions->item()->canRead($typeId, $entityId);
	}

	private function loadConversationStartedAt(ActivityContext $context): ?int
	{
		$threadId = (int)($context->activity['THREAD_ID'] ?? 0);
		if ($threadId <= 0)
		{
			$activity = ActivityTable::query()
				->setSelect(['THREAD_ID'])
				->where('ID', $context->request->activityId)
				->where('PROVIDER_ID', Email::getId())
				->fetch()
			;
			$threadId = (int)($activity['THREAD_ID'] ?? 0);
		}
		if ($threadId <= 0 || $context->request->ownerTypeId <= 0 || $context->request->ownerId <= 0)
		{
			return null;
		}

		$activity = ActivityTable::query()
			->setSelect(['START_TIME'])
			->where('THREAD_ID', $threadId)
			->where('PROVIDER_ID', Email::getId())
			->where('BINDINGS.OWNER_TYPE_ID', $context->request->ownerTypeId)
			->where('BINDINGS.OWNER_ID', $context->request->ownerId)
			->whereNotNull('START_TIME')
			->setOrder(['START_TIME' => 'ASC', 'ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $this->extractTimestamp($activity['START_TIME'] ?? null);
	}

	private function loadLastAnalyzedAt(ActivityContext $context): ?int
	{
		$history = $this->summaryDataProvider->loadSummaryHistory(
			$context->request->activityId,
			$context->request->ownerTypeId,
			$context->request->ownerId,
		);

		foreach ($history as $summary)
		{
			if (!$this->isVisibleSummary($summary))
			{
				continue;
			}

			$value = $summary['createdAt'] ?? null;

			return is_numeric($value) ? (int)$value : null;
		}

		return null;
	}

	private function isVisibleSummary(array $summary): bool
	{
		$jobId = (int)($summary['jobId'] ?? 0);
		$text = (string)($summary['summary'] ?? '');

		return $jobId > 0 && $text !== '';
	}

	private function extractTimestamp(mixed $value): ?int
	{
		if ($value instanceof DateTime)
		{
			return $value->getTimestamp();
		}
		if (!is_string($value) || $value === '')
		{
			return null;
		}
		try
		{
			return (new DateTime($value, DateTime::getFormat()))->getTimestamp();
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
