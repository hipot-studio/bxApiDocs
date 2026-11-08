<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Draft;

use Bitrix\Mail\Helper\Attachment\Storage;
use Bitrix\Mail\Internal\Service\DateTime\UtcDateTimeFormatter;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class DraftView
{
	private const ATTACHMENT_PREVIEW_SIZE = 120;

	public function __construct(
		public readonly int $id,
		public readonly int $revision,
		public readonly string $contextType,
		public readonly ?int $crmEntityTypeId,
		public readonly ?int $crmEntityId,
		public readonly DateTime $updatedAt,
		public readonly DateTime $expiresAt,
		public readonly DraftSnapshot $snapshot,
		public readonly array $attachments,
	)
	{
	}

	public static function fromRow(array $row, array $attachments = []): self
	{
		$recipients = Json::decode((string)$row['RECIPIENTS_DATA']);
		$sender = $row['SENDER_DATA'] !== null ? Json::decode((string)$row['SENDER_DATA']) : null;
		$body = (string)($row['BODY'] ?? '');
		$largeAttachments = ($row['LARGE_ATTACHMENTS_DATA'] ?? null) !== null
			? Json::decode((string)$row['LARGE_ATTACHMENTS_DATA'])
			: [];
		$inlineObjectIds = [];
		foreach ($attachments as $attachment)
		{
			$sourceObjectId = (int)($attachment['SOURCE_OBJECT_ID'] ?? 0);
			$objectId = (int)($attachment['OBJECT_ID'] ?? 0);
			if ($sourceObjectId > 0 && $objectId > 0 && $sourceObjectId !== $objectId)
			{
				$inlineObjectIds[$sourceObjectId] = $objectId;
			}
		}
		if ($inlineObjectIds !== [])
		{
			$body = preg_replace_callback(
				'/bxacid:n?(\d+)/i',
				static fn(array $matches): string => isset($inlineObjectIds[(int)$matches[1]])
					? 'bxacid:n' . $inlineObjectIds[(int)$matches[1]]
					: $matches[0],
				$body,
			);
		}

		return new self(
			id: (int)$row['ID'],
			revision: (int)$row['REVISION'],
			contextType: (string)$row['CONTEXT_TYPE'],
			crmEntityTypeId: isset($row['CRM_ENTITY_TYPE_ID']) ? (int)$row['CRM_ENTITY_TYPE_ID'] : null,
			crmEntityId: isset($row['CRM_ENTITY_ID']) ? (int)$row['CRM_ENTITY_ID'] : null,
			updatedAt: $row['DATE_MODIFY'],
			expiresAt: $row['DATE_EXPIRE'],
			snapshot: DraftSnapshot::fromArray([
				'clientId' => $row['CLIENT_ID'],
				'sender' => $sender,
				'to' => $recipients['to'] ?? [],
				'cc' => $recipients['cc'] ?? [],
				'bcc' => $recipients['bcc'] ?? [],
				'subject' => $row['SUBJECT'] ?? '',
				'body' => $body,
				'bodyFormat' => $row['BODY_FORMAT'],
				'mode' => $row['COMPOSE_MODE'],
				'parentMessageId' => $row['PARENT_MESSAGE_ID'],
				'attachments' => array_map(
					static fn(array $attachment): array => [
						'source' => 'draft',
						'id' => (string)$attachment['ID'],
					],
					$attachments,
				),
				'largeAttachments' => $largeAttachments,
			]),
			attachments: array_map(
				static fn(array $attachment): array => [
					'id' => (int)$attachment['OBJECT_ID'],
					'sourceObjectId' => (int)$attachment['SOURCE_OBJECT_ID'],
					'name' => (string)$attachment['FILE_NAME'],
					'size' => (int)$attachment['FILE_SIZE'],
					'contentType' => $attachment['CONTENT_TYPE'],
					'contentId' => $attachment['CONTENT_ID'] ?? null,
					'isInline' => ($attachment['IS_INLINE'] ?? false) === true
						|| ($attachment['IS_INLINE'] ?? null) === 'Y',
					'url' => isset($attachment['URL']) ? (string)$attachment['URL'] : null,
					'previewUrl' => isset($attachment['PREVIEW_URL']) ? (string)$attachment['PREVIEW_URL'] : null,
					'previewWidth' => isset($attachment['PREVIEW_WIDTH']) ? (int)$attachment['PREVIEW_WIDTH'] : null,
					'previewHeight' => isset($attachment['PREVIEW_HEIGHT'])
						? (int)$attachment['PREVIEW_HEIGHT']
						: null,
				],
				$attachments,
			),
		);
	}

	/**
	 * Signed disk links let the client open and preview the attachment. Every request is still
	 * checked by MailSecurityContext, which grants a draft attachment only to the draft author.
	 *
	 * @param \Bitrix\Disk\File $diskObject Disk object of the stored attachment.
	 * @param bool|null $isImage Image flag of the object, resolved per object when null.
	 *
	 * @return array{URL: ?string, PREVIEW_URL: ?string, PREVIEW_WIDTH: ?int, PREVIEW_HEIGHT: ?int}
	 */
	public static function buildAttachmentLinks($diskObject, ?bool $isImage = null): array
	{
		$url = Storage::getFileUrl($diskObject);
		$previewUrl = Storage::getFilePreviewUrl($diskObject, self::ATTACHMENT_PREVIEW_SIZE, [], $isImage);

		return [
			'URL' => $url,
			'PREVIEW_URL' => $previewUrl,
			'PREVIEW_WIDTH' => $previewUrl === null ? null : self::ATTACHMENT_PREVIEW_SIZE,
			'PREVIEW_HEIGHT' => $previewUrl === null ? null : self::ATTACHMENT_PREVIEW_SIZE,
		];
	}

	public function mapSourceObjectIds(array $sourceObjectIds): ?array
	{
		$objectIdsBySource = [];
		foreach ($this->attachments as $attachment)
		{
			$sourceObjectId = (int)($attachment['sourceObjectId'] ?? 0);
			$objectId = (int)($attachment['id'] ?? 0);
			if ($sourceObjectId > 0 && $objectId > 0)
			{
				$objectIdsBySource[$sourceObjectId] = $objectId;
			}
		}

		$objectIds = [];
		foreach ($sourceObjectIds as $sourceObjectId)
		{
			if (!isset($objectIdsBySource[(int)$sourceObjectId]))
			{
				return null;
			}
			$objectIds[] = $objectIdsBySource[(int)$sourceObjectId];
		}

		return $objectIds;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'revision' => $this->revision,
			'contextType' => $this->contextType,
			'crmEntityTypeId' => $this->crmEntityTypeId,
			'crmEntityId' => $this->crmEntityId,
			'updatedAt' => UtcDateTimeFormatter::format($this->updatedAt),
			'expiresAt' => UtcDateTimeFormatter::format($this->expiresAt),
			'snapshot' => $this->snapshot->toArray(),
			'attachments' => array_map(
				static fn(array $attachment): array => [
					'id' => $attachment['id'],
					// The object this copy was made from, when it is a copy. A set of the large attachment
					// scenario is signed over the source objects, so a client that only knows the copies
					// cannot extend the set the draft already carries and loses it on the next autosave.
					'sourceObjectId' => ($attachment['sourceObjectId'] ?? 0) > 0
						? (int)$attachment['sourceObjectId']
						: null,
					'name' => $attachment['name'],
					'size' => $attachment['size'],
					'contentType' => $attachment['contentType'],
					'contentId' => $attachment['contentId'],
					'isInline' => $attachment['isInline'],
					'url' => $attachment['url'] ?? null,
					'previewUrl' => $attachment['previewUrl'] ?? null,
					'previewWidth' => $attachment['previewWidth'] ?? null,
					'previewHeight' => $attachment['previewHeight'] ?? null,
				],
				$this->attachments,
			),
		];
	}
}
