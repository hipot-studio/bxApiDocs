<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\MailTemplate;

use Bitrix\Crm\MailTemplate\MailTemplateAccess;
use Bitrix\Main\Loader;
use Closure;

final class LegacyMailTemplateRepository
{
	private const MAX_LIMIT = 50;
	private const MAX_OFFSET = 1000;

	public function __construct(private readonly ?Closure $intranetLoader = null)
	{
	}

	/**
	 * @return array{
	 *     items: list<array{
	 *         id: int,
	 *         title: string,
	 *         subject: string,
	 *         scope: int,
	 *         entityTypeId: int,
	 *         bodyType: int
	 *     }>,
	 *     hasMore: bool
	 * }
	 */
	public function getList(
		int $userId,
		string $search = '',
		int $offset = 0,
		int $limit = 20,
		bool $includeContextual = true,
	): array
	{
		if ($userId <= 0)
		{
			return ['items' => [], 'hasMore' => false];
		}

		$offset = max(0, $offset);
		$limit = min(max(1, $limit), self::MAX_LIMIT);
		if ($offset > self::MAX_OFFSET)
		{
			return ['items' => [], 'hasMore' => false];
		}

		$filter = $this->buildAvailableFilter($userId);
		if (!$includeContextual)
		{
			$filter['=ENTITY_TYPE_ID'] = 0;
		}

		$search = trim($search);
		if ($search !== '')
		{
			$filter['%TITLE'] = $search;
		}

		$result = \CCrmMailTemplate::GetList(
			['SORT' => 'ASC', 'ID' => 'DESC'],
			$filter,
			false,
			['nTopCount' => $offset + $limit + 1],
			['ID', 'TITLE', 'SUBJECT', 'SCOPE', 'ENTITY_TYPE_ID', 'BODY_TYPE'],
		);

		$rows = [];
		while ($row = $result->Fetch())
		{
			$rows[] = $this->mapListRow($row);
		}

		$rows = array_slice($rows, $offset, $limit + 1);
		$hasMore = count($rows) > $limit;
		if ($hasMore)
		{
			array_pop($rows);
		}

		return ['items' => $rows, 'hasMore' => $hasMore];
	}

	/**
	 * @param int[] $templateIds
	 * @return list<array{
	 *     id: int,
	 *     title: string,
	 *     subject: string,
	 *     scope: int,
	 *     entityTypeId: int,
	 *     bodyType: int
	 * }>
	 */
	public function getByIds(array $templateIds, int $userId): array
	{
		$templateIds = array_values(array_unique(array_filter(
			array_map('intval', $templateIds),
			static fn(int $templateId): bool => $templateId > 0,
		)));
		$templateIds = array_slice($templateIds, 0, self::MAX_LIMIT);
		if ($userId <= 0 || empty($templateIds))
		{
			return [];
		}

		$filter = $this->buildAvailableFilter($userId);
		$filter['@ID'] = $templateIds;
		$result = \CCrmMailTemplate::GetList(
			[],
			$filter,
			false,
			['nTopCount' => count($templateIds)],
			['ID', 'TITLE', 'SUBJECT', 'SCOPE', 'ENTITY_TYPE_ID', 'BODY_TYPE'],
		);

		$rowsById = [];
		while ($row = $result->Fetch())
		{
			$mapped = $this->mapListRow($row);
			$rowsById[$mapped['id']] = $mapped;
		}

		$rows = [];
		foreach ($templateIds as $templateId)
		{
			if (isset($rowsById[$templateId]))
			{
				$rows[] = $rowsById[$templateId];
			}
		}

		return $rows;
	}

	/**
	 * @return list<array{
	 *     id: int,
	 *     title: string,
	 *     subject: string,
	 *     scope: int,
	 *     entityTypeId: int,
	 *     bodyType: int
	 * }>
	 */
	public function getRecentlyCreated(
		int $userId,
		int $limit = 5,
		bool $includeContextual = true,
	): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$limit = min(max(1, $limit), self::MAX_LIMIT);
		$filter = $this->buildAvailableFilter($userId);
		if (!$includeContextual)
		{
			$filter['=ENTITY_TYPE_ID'] = 0;
		}

		$result = \CCrmMailTemplate::GetList(
			['CREATED' => 'DESC', 'ID' => 'DESC'],
			$filter,
			false,
			['nTopCount' => $limit],
			['ID', 'TITLE', 'SUBJECT', 'SCOPE', 'ENTITY_TYPE_ID', 'BODY_TYPE'],
		);

		$rows = [];
		while ($row = $result->Fetch())
		{
			$rows[] = $this->mapListRow($row);
		}

		return $rows;
	}

	/**
	 * @return array{id: int, subject: string, body: string, bodyType: int, attachmentIds: list<int>}|null
	 */
	public function getUniversalForPreparation(int $templateId, int $userId): ?array
	{
		if ($templateId <= 0 || $userId <= 0)
		{
			return null;
		}

		$filter = $this->buildAvailableFilter($userId);
		$filter['=ID'] = $templateId;
		$filter['=ENTITY_TYPE_ID'] = 0;
		$result = \CCrmMailTemplate::GetList(
			[],
			$filter,
			false,
			['nTopCount' => 1],
			['ID', 'SUBJECT', 'BODY', 'BODY_TYPE'],
		);
		$row = $result->Fetch();
		if (!is_array($row))
		{
			return null;
		}

		return [
			'id' => (int)$row['ID'],
			'subject' => (string)$row['SUBJECT'],
			'body' => (string)$row['BODY'],
			'bodyType' => (int)$row['BODY_TYPE'],
			'attachmentIds' => $this->getAttachmentIds((int)$row['ID']),
		];
	}

	/**
	 * @param list<int> $diskObjectIds
	 */
	public function addPersonalUniversal(
		string $subject,
		string $bodyHtml,
		string $emailFrom,
		array $diskObjectIds,
		int $userId,
	): int
	{
		$fields = [
			'TITLE' => $subject,
			'IS_ACTIVE' => 'Y',
			'OWNER_ID' => $userId,
			'SCOPE' => \CCrmMailTemplateScope::Personal,
			'ENTITY_TYPE_ID' => 0,
			'EMAIL_FROM' => $emailFrom,
			'SUBJECT' => $subject,
			'BODY_TYPE' => \CCrmContentType::Html,
			'BODY' => $bodyHtml,
			'UF_ATTACHMENT' => $this->mapDiskObjectIdsToUserFieldValues($diskObjectIds),
			'SORT' => 100,
		];

		return (int)\CCrmMailTemplate::add($fields, [
			'CURRENT_USER_ID' => $userId,
		]);
	}

	/** @return list<string> */
	public function getLastErrorMessages(): array
	{
		return array_values(array_filter(
			array_map('strval', \CCrmMailTemplate::getErrorMessages()),
			static fn(string $errorMessage): bool => $errorMessage !== '',
		));
	}

	/**
	 * Templates shared with departments are resolved through intranet, so without that module the scope
	 * is left out instead of being asked for: personal and common templates stay available.
	 */
	private function buildAvailableFilter(int $userId): array
	{
		$scope = [
			'LOGIC' => 'OR',
			'__INNER_FILTER_PERSONAL' => ['=OWNER_ID' => $userId],
			'__INNER_FILTER_COMMON' => ['=SCOPE' => \CCrmMailTemplateScope::Common],
		];

		if ($this->isIntranetAvailable())
		{
			$scope['__INNER_FILTER_LIMITED'] = [
				'@ID' => MailTemplateAccess::getAllAvailableSharedTemplatesId($userId),
			];
		}

		return [
			'=IS_ACTIVE' => 'Y',
			'__INNER_FILTER_SCOPE' => $scope,
		];
	}

	private function isIntranetAvailable(): bool
	{
		return $this->intranetLoader !== null
			? (bool)($this->intranetLoader)()
			: Loader::includeModule('intranet')
		;
	}

	/**
	 * @return list<int>
	 */
	private function getAttachmentIds(int $templateId): array
	{
		global $USER_FIELD_MANAGER;

		$values = $USER_FIELD_MANAGER->getUserFieldValue('CRM_MAIL_TEMPLATE', 'UF_ATTACHMENT', $templateId);
		if (!is_array($values))
		{
			$values = $values !== null && $values !== false && $values !== '' ? [$values] : [];
		}

		$attachmentIds = [];
		foreach ($values as $value)
		{
			$attachmentId = (int)$value;
			if ($attachmentId > 0)
			{
				$attachmentIds[] = $attachmentId;
			}
		}

		return $attachmentIds;
	}

	/**
	 * @param list<int> $diskObjectIds
	 * @return list<string>
	 */
	private function mapDiskObjectIdsToUserFieldValues(array $diskObjectIds): array
	{
		$normalizedIds = [];
		$seenIds = [];
		foreach ($diskObjectIds as $diskObjectId)
		{
			$diskObjectId = (int)$diskObjectId;
			if ($diskObjectId <= 0 || isset($seenIds[$diskObjectId]))
			{
				continue;
			}

			$normalizedIds[] = 'n' . $diskObjectId;
			$seenIds[$diskObjectId] = true;
		}

		return $normalizedIds;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{
	 *     id: int,
	 *     title: string,
	 *     subject: string,
	 *     scope: int,
	 *     entityTypeId: int,
	 *     bodyType: int
	 * }
	 */
	private function mapListRow(array $row): array
	{
		return [
			'id' => (int)$row['ID'],
			'title' => (string)$row['TITLE'],
			'subject' => (string)$row['SUBJECT'],
			'scope' => (int)$row['SCOPE'],
			'entityTypeId' => (int)$row['ENTITY_TYPE_ID'],
			'bodyType' => (int)$row['BODY_TYPE'],
		];
	}
}
