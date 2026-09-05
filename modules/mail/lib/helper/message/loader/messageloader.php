<?php

namespace Bitrix\Mail\Helper\Message\Loader;

use Bitrix\Mail\Helper\Attachment\Storage;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Service\FavoritesService;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\IO\Path;
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

		$detailsQuery = QueryBuilder::buildWebMessagesDetailsQuery(
			$itemIds,
			$filter->getArray()
		);

		$messages = self::aggregateMessages($detailsQuery->fetchAll());

		if (Feature::isMailListImprovementsAvailable())
		{
			if ($filter->needsAttachmentsStack())
			{
				$messages = self::prepareAttachments($messages);
			}

			$messages = self::prepareFavorites($messages, $filter->getUserId());
		}

		return $messages;
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
			$row['BIND'] = (array)$row['BIND'];

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

		$rows = self::discardRowsWithoutFile(self::fetchAttachmentRows($messageIds));
		$counts = self::countRowsByMessageId($rows);
		$iconRows = self::groupIconRowsByMessageId($rows);

		foreach ($items as $index => $item)
		{
			$messageId = (int)$item['MESSAGE_ID'];
			$count = $counts[$messageId] ?? 0;
			if ($count <= 0)
			{
				continue;
			}

			$items[$index]['__attachments_stack'] = self::buildAttachmentStack(
				$count,
				$iconRows[$messageId] ?? [],
			);
		}

		return $items;
	}

	/**
	 * @param int[] $messageIds
	 * @return array Live attachment rows (MESSAGE_ID, FILE_ID, FILE_NAME) ordered within each message
	 */
	private static function fetchAttachmentRows(array $messageIds): array
	{
		return MailMessageAttachmentTable::query()
			->setSelect(['MESSAGE_ID', 'FILE_ID', 'FILE_NAME'])
			->whereIn('MESSAGE_ID', $messageIds)
			->where('FILE_ID', '>', 0)
			->setOrder(['MESSAGE_ID' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;
	}

	private static function discardRowsWithoutFile(array $rows): array
	{
		$fileIds = [];
		foreach ($rows as $row)
		{
			$fileIds[(int)($row['FILE_ID'] ?? 0)] = true;
		}

		return self::filterRowsByExistingFiles($rows, self::fetchExistingFileIds(array_keys($fileIds)));
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
	 * @param int[] $fileIds
	 * @return array<int, true>
	 */
	private static function fetchExistingFileIds(array $fileIds): array
	{
		return array_fill_keys(array_keys(self::fetchFileRows($fileIds)), true);
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
	public static function buildAttachmentStack(int $count, array $iconRows): ?array
	{
		if ($count <= 0)
		{
			return null;
		}

		$icons = [];
		foreach (array_slice($iconRows, 0, self::STACK_ICONS_LIMIT) as $row)
		{
			$name = (string)($row['FILE_NAME'] ?? '');
			$icons[] = [
				'name' => $name,
				'extension' => self::extractExtension($name),
			];
		}

		return ['count' => $count, 'icons' => $icons];
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