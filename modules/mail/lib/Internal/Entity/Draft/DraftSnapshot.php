<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Draft;

final class DraftSnapshot
{
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
	private const ALLOWED_MODES = ['new', 'reply', 'forward'];
	private const MAX_LARGE_ATTACHMENT_SETS = 100;
	private const MAX_FILES_PER_LARGE_ATTACHMENT = 100;

	/** @param DraftAttachmentSource[] $attachments */
	private function __construct(
		public readonly string $clientId,
		public readonly ?array $sender,
		public readonly array $to,
		public readonly array $cc,
		public readonly array $bcc,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $bodyFormat,
		public readonly string $mode,
		public readonly ?int $parentMessageId,
		public readonly array $attachments,
		public readonly array $largeAttachments,
	)
	{
	}

	public static function fromArray(array $data): self
	{
		$clientId = (string)($data['clientId'] ?? '');
		$mode = (string)($data['mode'] ?? '');
		$bodyFormat = (string)($data['bodyFormat'] ?? '');

		if (!preg_match(self::UUID_PATTERN, $clientId))
		{
			throw new \InvalidArgumentException('Invalid draft client identifier.');
		}
		if (!in_array($mode, self::ALLOWED_MODES, true) || $bodyFormat !== 'html')
		{
			throw new \InvalidArgumentException('Invalid draft compose mode or body format.');
		}

		$sender = self::normalizeSender($data['sender'] ?? null);
		$to = self::normalizeRecipients($data['to'] ?? []);
		$cc = self::normalizeRecipients($data['cc'] ?? []);
		$bcc = self::normalizeRecipients($data['bcc'] ?? []);
		$attachments = [];
		foreach (array_values((array)($data['attachments'] ?? [])) as $source)
		{
			$attachment = DraftAttachmentSource::fromArray($source);
			// A repeated source would insert a duplicate binding and break UX_B_MAIL_DRAFT_ATTACHMENT_FILE.
			$attachments[$attachment->source . ':' . $attachment->id] = $attachment;
		}
		$attachments = array_values($attachments);
		$largeAttachments = self::normalizeLargeAttachments($data['largeAttachments'] ?? []);

		$parentMessageId = isset($data['parentMessageId']) ? (int)$data['parentMessageId'] : null;
		if ($parentMessageId !== null && $parentMessageId <= 0)
		{
			throw new \InvalidArgumentException('Invalid parent message identifier.');
		}

		return new self(
			clientId: $clientId,
			sender: $sender,
			to: $to,
			cc: $cc,
			bcc: $bcc,
			subject: trim(strip_tags((string)($data['subject'] ?? ''))),
			body: (string)($data['body'] ?? ''),
			bodyFormat: $bodyFormat,
			mode: $mode,
			parentMessageId: $parentMessageId,
			attachments: $attachments,
			largeAttachments: $largeAttachments,
		);
	}

	public function withBody(string $body): self
	{
		$data = $this->toArray();
		$data['body'] = $body;

		return self::fromArray($data);
	}

	public function withLargeAttachments(array $largeAttachments): self
	{
		$data = $this->toArray();
		$data['largeAttachments'] = $largeAttachments;

		return self::fromArray($data);
	}

	public function isMeaningful(): bool
	{
		$plainBody = trim(html_entity_decode(strip_tags($this->body), ENT_QUOTES | ENT_HTML5));

		return $this->subject !== ''
			|| $this->sender !== null
			|| $plainBody !== ''
			|| $this->to !== []
			|| $this->cc !== []
			|| $this->bcc !== []
			|| $this->attachments !== []
		;
	}

	public function toArray(): array
	{
		return [
			'clientId' => $this->clientId,
			'sender' => $this->sender,
			'to' => $this->to,
			'cc' => $this->cc,
			'bcc' => $this->bcc,
			'subject' => $this->subject,
			'body' => $this->body,
			'bodyFormat' => $this->bodyFormat,
			'mode' => $this->mode,
			'parentMessageId' => $this->parentMessageId,
			'attachments' => array_map(
				static fn(DraftAttachmentSource $source): array => $source->toArray(),
				$this->attachments,
			),
			'largeAttachments' => $this->largeAttachments,
		];
	}

	private static function normalizeLargeAttachments(mixed $largeAttachments): array
	{
		if (!is_array($largeAttachments))
		{
			throw new \InvalidArgumentException('Invalid draft large attachments.');
		}
		if (count($largeAttachments) > self::MAX_LARGE_ATTACHMENT_SETS)
		{
			throw new \InvalidArgumentException('Too many draft large attachments.');
		}

		$result = [];
		foreach (array_values($largeAttachments) as $largeAttachment)
		{
			if (!is_array($largeAttachment))
			{
				throw new \InvalidArgumentException('Invalid draft large attachment.');
			}

			$token = trim((string)($largeAttachment['token'] ?? ''));
			$publicUrl = trim((string)($largeAttachment['publicUrl'] ?? ''));
			$fileIds = array_values(array_unique(array_map('intval', (array)($largeAttachment['fileIds'] ?? []))));
			sort($fileIds, SORT_NUMERIC);
			$sourceFileIds = array_values(array_unique(array_map(
				'intval',
				(array)($largeAttachment['sourceFileIds'] ?? $fileIds),
			)));
			sort($sourceFileIds, SORT_NUMERIC);
			$tokenFileIds = array_values(array_unique(array_map(
				'intval',
				(array)($largeAttachment['tokenFileIds'] ?? $sourceFileIds),
			)));
			sort($tokenFileIds, SORT_NUMERIC);
			if (
				$token === ''
				|| strlen($token) > 4096
				|| $publicUrl === ''
				|| strlen($publicUrl) > 2048
				|| $fileIds === []
				|| $fileIds[0] <= 0
				|| count($fileIds) > self::MAX_FILES_PER_LARGE_ATTACHMENT
				|| $sourceFileIds === []
				|| $sourceFileIds[0] <= 0
				|| count($sourceFileIds) !== count($fileIds)
				|| $tokenFileIds === []
				|| $tokenFileIds[0] <= 0
				|| count($tokenFileIds) !== count($fileIds)
			)
			{
				throw new \InvalidArgumentException('Invalid draft large attachment.');
			}

			$result[$token] = [
				'token' => $token,
				'publicUrl' => $publicUrl,
				'fileIds' => $fileIds,
				'sourceFileIds' => $sourceFileIds,
				'tokenFileIds' => $tokenFileIds,
			];
		}

		return array_values($result);
	}

	private static function normalizeSender(mixed $sender): ?array
	{
		if ($sender === null)
		{
			return null;
		}
		if (!is_array($sender))
		{
			throw new \InvalidArgumentException('Invalid draft sender.');
		}

		$email = trim((string)($sender['email'] ?? ''));
		if (!\Bitrix\Main\Mail\Address::isValid($email))
		{
			throw new \InvalidArgumentException('Invalid draft sender email.');
		}

		// Two connections of one address share the formatted string, so the mailbox of the draft is kept
		// to restore the exact one the letter was written from.
		$mailboxId = (int)($sender['mailboxId'] ?? 0);

		return [
			'name' => trim((string)($sender['name'] ?? '')),
			'email' => $email,
			...($mailboxId > 0 ? ['mailboxId' => $mailboxId] : []),
		];
	}

	private static function normalizeRecipients(mixed $recipients): array
	{
		if (!is_array($recipients))
		{
			throw new \InvalidArgumentException('Invalid draft recipients.');
		}

		$result = [];
		foreach ($recipients as $recipient)
		{
			if (!is_array($recipient))
			{
				throw new \InvalidArgumentException('Invalid draft recipient.');
			}
			$email = trim((string)($recipient['email'] ?? ''));
			if (!\Bitrix\Main\Mail\Address::isValid($email))
			{
				throw new \InvalidArgumentException('Invalid draft recipient email.');
			}

			$normalized = [
				'name' => trim((string)($recipient['name'] ?? '')),
				'email' => $email,
			];
			if (isset($recipient['entityType']))
			{
				$normalized['entityType'] = (string)$recipient['entityType'];
			}
			if (isset($recipient['entityId']))
			{
				$normalized['entityId'] = (int)$recipient['entityId'];
			}
			if (isset($recipient['avatar']))
			{
				$avatar = trim((string)$recipient['avatar']);
				if ($avatar !== '' && strlen($avatar) <= 2048)
				{
					$normalized['avatar'] = $avatar;
				}
			}
			$result[] = $normalized;
		}

		return $result;
	}
}
