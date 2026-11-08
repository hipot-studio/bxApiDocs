<?php

namespace Bitrix\Mail\Helper\Message\Loader;

use Bitrix\Mail\Helper\Attachment\Storage;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Service\FavoritesService;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\UI\Viewer;
use Bitrix\Mail\Helper\Dto\MessageContact;
use Bitrix\Main\Mail\Address;

class MessageLoader
{
	private const STACK_ICONS_LIMIT = 2;
	private const NO_LIVE_ATTACHMENTS_STACK = ['count' => 0, 'icons' => []];

	/**
	 * @param MessageFilter $filter
	 * @param PageNavigation $navigation Pagination object
	 * @return array Array of message items with aggregated BIND and CRM_ACTIVITY
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getMessageList(
		MessageFilter $filter,
		PageNavigation $navigation,
	): array
	{
		// +1 to fetch one extra record for "has next page" check
		$query = QueryBuilder::buildMailMessageListQuery(
			$filter->getArray(),
			$navigation->getLimit() + 1,
			$navigation->getOffset(),
		);

		$itemIds = array_column($query->fetchAll(), 'DISTINCT_ID');

		if (empty($itemIds))
		{
			return [];
		}

		$detailsQuery = QueryBuilder::buildWebMessagesDetailsQuery($itemIds, $filter->getArray());

		$messages = self::aggregateMessages($detailsQuery->fetchAll());
		$messages = self::prepareBindings($messages);

		return self::decorateListRows($messages, $filter->getUserId(), $filter->needsAttachmentsStack());
	}

	/**
	 * Marks the plain list adds to its rows once the details are read: the favorite star and the stack
	 * of attachments. They belong to the row model rather than to the list that built it, so a caller
	 * assembling the very same rows out of {@see QueryBuilder::buildWebMessagesDetailsQuery()}, as the
	 * grid grouped by conversations does, decorates them through here and shows the same row.
	 *
	 * @param array $items rows of the details query, one per message
	 * @param int|null $userId owner of the favorites, null when the caller shows none
	 */
	public static function decorateListRows(array $items, ?int $userId, bool $withAttachmentsStack): array
	{
		if (!Feature::isMailListImprovementsAvailable())
		{
			return $items;
		}

		if ($withAttachmentsStack)
		{
			$items = self::prepareAttachments($items);
		}

		return self::prepareFavorites($items, $userId);
	}

	public static function buildContactList($fieldValue): array
	{
		$addressList = Message::parseAddressList($fieldValue);

		$processedAddressesList = [];

		foreach ($addressList as $address)
		{
			$processedAddress = new Address($address);
			if ($processedAddress->validate())
			{
				$messageContact = new MessageContact();
				$messageContact->email = $processedAddress->getEmail();
				$messageContact->name = $processedAddress->getName();

				if (empty($messageContact->name))
				{
					$messageContact->name = $messageContact->email;
				}

				$processedAddressesList[] = $messageContact;
			}
		}

		return $processedAddressesList;
	}

	/**
	 * @param array $rows
	 * @return array
	 */
	private static function aggregateMessages(array $rows): array
	{
		$messageList = [];

		foreach($rows as $row)
		{
			$messageId = $row['MESSAGE_ID'];
			$row['BIND'] = (array)($row['BIND'] ?? []);

			if(!array_key_exists($messageId, $messageList))
			{
				$row['CRM_ACTIVITY_OWNER'] = (array)@$row['CRM_ACTIVITY_OWNER'];
				$messageList[$messageId] = $row;
			}
			else
			{
				$messageList[$messageId]['BIND'] = array_unique(
					array_filter(
						array_merge(
							$messageList[$messageId]['BIND'],
							$row['BIND'],
						),
					),
				);

				$row['CRM_ACTIVITY_OWNER'] = (array)@$row['CRM_ACTIVITY_OWNER'];
				$messageList[$messageId]['CRM_ACTIVITY_OWNER'] = array_unique(
					array_filter(
						array_merge(
							$messageList[$messageId]['CRM_ACTIVITY_OWNER'],
							$row['CRM_ACTIVITY_OWNER'],
						),
					),
				);

				$messageList[$messageId]['IS_SEEN'] = max($messageList[$messageId]['IS_SEEN'], $row['IS_SEEN']);
			}
		}

		return array_values($messageList);
	}

	private static function prepareBindings(array $messages): array
	{
		$messageIndexes = [];
		$mailboxIds = [];
		foreach ($messages as $index => $message)
		{
			$messageIndexes[(int)$message['MESSAGE_ID']] = $index;
			$mailboxIds[(int)$message['MAILBOX_ID']] = true;
			$messages[$index]['BIND'] = [];
			$messages[$index]['CRM_ACTIVITY_OWNER'] = [];
		}

		if ($messageIndexes === [])
		{
			return $messages;
		}

		$query = MessageAccessTable::query()
			->setSelect([
				'MESSAGE_ID',
				'ENTITY_TYPE',
				'ENTITY_ID',
			])
			->whereIn('MAILBOX_ID', array_keys($mailboxIds))
			->whereIn('MESSAGE_ID', array_keys($messageIndexes))
		;

		$bindings = [];
		$crmActivityMessages = [];
		foreach ($query->fetchAll() as $row)
		{
			$messageId = (int)$row['MESSAGE_ID'];
			$binding = "{$row['ENTITY_TYPE']}-{$row['ENTITY_ID']}";
			$bindings[$messageId][$binding] = true;

			if ($row['ENTITY_TYPE'] === MessageAccessTable::ENTITY_TYPE_CRM_ACTIVITY)
			{
				$crmActivityMessages[(int)$row['ENTITY_ID']][$messageId] = true;
			}
		}

		$crmOwners = self::loadCrmOwners($crmActivityMessages);

		foreach ($messageIndexes as $messageId => $index)
		{
			$messages[$index]['BIND'] = array_keys($bindings[$messageId] ?? []);
			$messages[$index]['CRM_ACTIVITY_OWNER'] = array_keys($crmOwners[$messageId] ?? []);
		}

		return $messages;
	}

	private static function loadCrmOwners(array $activityMessages): array
	{
		if ($activityMessages === [] || !Loader::includeModule('crm'))
		{
			return [];
		}

		$owners = [];
		$rows = \Bitrix\Crm\ActivityTable::query()
			->setSelect([
				'ID',
				'OWNER_TYPE_ID',
				'OWNER_ID',
			])
			->whereIn('ID', array_keys($activityMessages))
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$ownerTypeId = (int)$row['OWNER_TYPE_ID'];
			$ownerId = (int)$row['OWNER_ID'];
			if ($ownerTypeId <= 0 || $ownerId <= 0)
			{
				continue;
			}

			foreach (array_keys($activityMessages[(int)$row['ID']]) as $messageId)
			{
				$owners[$messageId]["{$ownerTypeId}-{$ownerId}"] = true;
			}
		}

		return $owners;
	}

	/**
	 * Null stack means the attachments of the message are not stored yet, so the summary is still on its
	 * way; a stack of zero files means it did arrive and none of the stored files is alive any more.
	 */
	private static function prepareAttachments(array $items): array
	{
		foreach ($items as $index => $item)
		{
			$items[$index]['__attachments_stack'] = null;
		}

		$messageIds = self::collectMessageIdsWithAttachments($items);
		if (empty($messageIds))
		{
			return $items;
		}

		$rows = self::fetchAttachmentRows($messageIds);
		if ($rows === [])
		{
			return $items;
		}

		$fileRows = self::fetchFileRows(self::collectRowFileIds($rows));
		$liveRows = self::filterRowsByExistingFiles($rows, array_fill_keys(array_keys($fileRows), true));

		$storedCounts = self::countRowsByMessageId($rows);
		$counts = self::countRowsByMessageId($liveRows);
		$iconRowsByMessageId = self::groupIconRowsByMessageId($liveRows);
		$diskObjects = $iconRowsByMessageId === []
			? []
			: Storage::getObjectsByFileIds(
				self::collectRowFileIds(array_merge(...array_values($iconRowsByMessageId))),
			)
		;

		foreach ($items as $index => $item)
		{
			$messageId = (int)$item['MESSAGE_ID'];
			if (($storedCounts[$messageId] ?? 0) <= 0)
			{
				continue;
			}

			$count = $counts[$messageId] ?? 0;
			if ($count <= 0)
			{
				$items[$index]['__attachments_stack'] = self::NO_LIVE_ATTACHMENTS_STACK;

				continue;
			}

			$items[$index]['__attachments_stack'] = self::buildAttachmentStack(
				$count,
				self::buildStackIcons($iconRowsByMessageId[$messageId] ?? [], $messageId, $fileRows, $diskObjects),
			);
		}

		return $items;
	}

	/**
	 * @param int[] $messageIds
	 * @return array Attachment rows (MESSAGE_ID, FILE_ID, FILE_NAME, FILE_SIZE) ordered within each message
	 */
	private static function fetchAttachmentRows(array $messageIds): array
	{
		return MailMessageAttachmentTable::query()
			->setSelect(['MESSAGE_ID', 'FILE_ID', 'FILE_NAME', 'FILE_SIZE'])
			->whereIn('MESSAGE_ID', $messageIds)
			->where('FILE_ID', '>', 0)
			->setOrder(['MESSAGE_ID' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;
	}

	/**
	 * @internal
	 * @return int[]
	 */
	public static function collectRowFileIds(array $rows): array
	{
		$fileIds = [];
		foreach ($rows as $row)
		{
			$fileId = (int)($row['FILE_ID'] ?? 0);
			if ($fileId > 0)
			{
				$fileIds[$fileId] = true;
			}
		}

		return array_keys($fileIds);
	}

	/**
	 * @internal
	 * @param array<int, true> $existingFileIds
	 */
	public static function filterRowsByExistingFiles(array $rows, array $existingFileIds): array
	{
		$liveRows = [];
		foreach ($rows as $row)
		{
			if (isset($existingFileIds[(int)($row['FILE_ID'] ?? 0)]))
			{
				$liveRows[] = $row;
			}
		}

		return $liveRows;
	}

	/**
	 * Reads every file once, so url and viewer attributes come from the same row.
	 *
	 * @param int[] $fileIds
	 * @return array<int, array> b_file rows by id
	 */
	private static function fetchFileRows(array $fileIds): array
	{
		$files = [];
		foreach ($fileIds as $fileId)
		{
			$file = \CFile::getFileArray($fileId);
			if (is_array($file))
			{
				$files[(int)$file['ID']] = $file;
			}
		}

		return $files;
	}

	/**
	 * @internal
	 * @return array<int, int> Live attachment count per message id
	 */
	public static function countRowsByMessageId(array $rows): array
	{
		$counts = [];
		foreach ($rows as $row)
		{
			$messageId = (int)($row['MESSAGE_ID'] ?? 0);
			if ($messageId <= 0)
			{
				continue;
			}

			$counts[$messageId] = ($counts[$messageId] ?? 0) + 1;
		}

		return $counts;
	}

	/** @internal */
	public static function groupIconRowsByMessageId(array $rows): array
	{
		$iconRows = [];
		foreach ($rows as $row)
		{
			$messageId = (int)($row['MESSAGE_ID'] ?? 0);
			if ($messageId <= 0 || count($iconRows[$messageId] ?? []) >= self::STACK_ICONS_LIMIT)
			{
				continue;
			}

			$iconRows[$messageId][] = $row;
		}

		return $iconRows;
	}

	/** @internal */
	public static function buildAttachmentStack(int $count, array $icons): ?array
	{
		if ($count <= 0)
		{
			return null;
		}

		return ['count' => $count, 'icons' => $icons];
	}

	/**
	 * Builds the same element shape the attachments popup returns, reusing rows read earlier:
	 * b_file rows come from the dead-attachment filtering step, so no file is read twice.
	 *
	 * @internal
	 * @param array $iconRows Attachment rows of a single message
	 * @param array<int, array> $fileRowsByFileId b_file rows by file id
	 * @param array $diskObjectsByFileId Disk objects by file id
	 */
	public static function buildStackIcons(
		array $iconRows,
		int $messageId,
		array $fileRowsByFileId,
		array $diskObjectsByFileId,
	): array
	{
		$icons = [];
		foreach (array_slice($iconRows, 0, self::STACK_ICONS_LIMIT) as $row)
		{
			$name = (string)($row['FILE_NAME'] ?? '');
			$fileId = (int)($row['FILE_ID'] ?? 0);
			$fileRow = $fileRowsByFileId[$fileId] ?? null;
			$url = self::resolveAttachmentUrl($diskObjectsByFileId[$fileId] ?? null, $fileRow);

			$icons[] = [
				'name' => $name,
				'extension' => self::extractExtension($name),
				'size' => (string)\CFile::formatSize((int)($row['FILE_SIZE'] ?? 0)),
				'url' => $url,
				'viewerAttrs' => $url === null
					? null
					: self::buildViewerAttributes($fileRow, $url, $name, $messageId),
			];
		}

		return $icons;
	}

	/** @internal */
	public static function collectMessageIdsWithAttachments(array $items): array
	{
		$messageIds = [];
		foreach ($items as $item)
		{
			if ((int)($item['ATTACHMENTS'] ?? 0) > 0)
			{
				$messageIds[] = (int)$item['MESSAGE_ID'];
			}
		}

		return $messageIds;
	}

	/** @internal */
	public static function groupAttachmentsByMessageId(array $rows): array
	{
		$attachmentsByMessage = [];
		foreach ($rows as $row)
		{
			$messageId = (int)($row['MESSAGE_ID'] ?? 0);
			if ($messageId <= 0)
			{
				continue;
			}

			$attachmentsByMessage[$messageId][] = self::mapAttachmentRow($row);
		}

		return $attachmentsByMessage;
	}

	private static function mapAttachmentRow(array $row): array
	{
		$name = (string)($row['FILE_NAME'] ?? '');

		return [
			'id' => (int)($row['ID'] ?? 0),
			'fileId' => (int)($row['FILE_ID'] ?? 0),
			'name' => $name,
			'size' => (int)($row['FILE_SIZE'] ?? 0),
			'extension' => self::extractExtension($name),
			'url' => null,
			'viewerAttrs' => null,
		];
	}

	private static function extractExtension(string $fileName): string
	{
		return mb_strtolower(Path::getExtension($fileName));
	}

	/** @internal */
	public static function fillAttachmentUrls(array $attachmentsByMessage): array
	{
		$fileIds = self::collectAttachmentFileIds($attachmentsByMessage);
		if (empty($fileIds))
		{
			return $attachmentsByMessage;
		}

		$diskObjectsByFileId = Storage::getObjectsByFileIds($fileIds);
		$fileRowsByFileId = self::fetchFileRows($fileIds);

		foreach ($attachmentsByMessage as $messageId => $attachments)
		{
			foreach ($attachments as $index => $attachment)
			{
				$fileId = (int)($attachment['fileId'] ?? 0);
				if ($fileId <= 0)
				{
					continue;
				}

				$fileRow = $fileRowsByFileId[$fileId] ?? null;

				$url = self::resolveAttachmentUrl($diskObjectsByFileId[$fileId] ?? null, $fileRow);
				if ($url === null)
				{
					continue;
				}

				$attachmentsByMessage[$messageId][$index]['url'] = $url;
				$attachmentsByMessage[$messageId][$index]['viewerAttrs'] = self::buildViewerAttributes(
					$fileRow,
					$url,
					$attachment['name'],
					(int)$messageId,
				);
			}

			$attachmentsByMessage[$messageId] = self::discardDeletedAttachments(
				$attachmentsByMessage[$messageId],
			);
		}

		return $attachmentsByMessage;
	}

	/** @internal */
	public static function collectAttachmentFileIds(array $attachmentsByMessage): array
	{
		$fileIds = [];
		foreach ($attachmentsByMessage as $attachments)
		{
			foreach ($attachments as $attachment)
			{
				$fileId = (int)($attachment['fileId'] ?? 0);
				if ($fileId > 0)
				{
					$fileIds[$fileId] = true;
				}
			}
		}

		return array_keys($fileIds);
	}

	/** @internal */
	public static function discardDeletedAttachments(array $attachments): array
	{
		$liveAttachments = [];
		foreach ($attachments as $attachment)
		{
			if (($attachment['url'] ?? null) !== null)
			{
				$liveAttachments[] = $attachment;
			}
		}

		return $liveAttachments;
	}

	private static function resolveAttachmentUrl($diskObject, ?array $fileRow): ?string
	{
		if ($diskObject)
		{
			$urlManager = Storage::getUrlManager();
			if ($urlManager)
			{
				return (string)$urlManager->getUrlForShowFile($diskObject);
			}
		}

		if ($fileRow === null)
		{
			return null;
		}

		$src = $fileRow['SRC'] ?? \CFile::getFileSRC($fileRow);

		return $src ? (string)$src : null;
	}

	private static function buildViewerAttributes(?array $fileRow, string $url, string $name, int $messageId): array
	{
		$attributes = $fileRow === null
			? Viewer\ItemAttributes::buildAsUnknownType($url)
			: Viewer\ItemAttributes::tryBuildByFileData($fileRow, $url);

		return $attributes
			->setTitle($name)
			->setGroupBy(sprintf('mail_msg_%u_file', $messageId))
			->addAction(['type' => 'download'])
			->toVueBind()
		;
	}

	private static function prepareFavorites(array $items, ?int $userId): array
	{
		if ($userId === null || $userId <= 0)
		{
			return $items;
		}

		$messageIds = self::collectFavoriteMessageIds($items);
		$favoriteSet = $messageIds === []
			? []
			: array_flip((new FavoritesService())->getFavoriteMessageIds($userId, $messageIds));

		foreach ($items as $index => $item)
		{
			$items[$index]['__is_favorite'] = isset($favoriteSet[(int)$item['MESSAGE_ID']]);
		}

		return $items;
	}

	private static function collectFavoriteMessageIds(array $items): array
	{
		$messageIds = [];
		foreach ($items as $item)
		{
			$messageId = (int)($item['MESSAGE_ID'] ?? 0);
			if ($messageId > 0)
			{
				$messageIds[$messageId] = true;
			}
		}

		return array_keys($messageIds);
	}
}
