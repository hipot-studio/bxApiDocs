<?php

namespace Bitrix\Disk\Document\Models;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Type\Contract\Jsonable;
use Bitrix\Main\Web\Json;

final class DocumentSessionContext implements Jsonable
{
	/** @var int|null */
	protected $attachedObjectId;
	/** @var AttachedObject */
	protected $attachedObject;
	/** @var int|null */
	protected $externalLinkId;
	/** @var ExternalLink */
	protected $externalLink;
	/** @var int */
	protected $objectId;
	/** @var BaseObject|File */
	protected $object;
	protected ?int $htmlBaselineVersion = null;
	protected ?int $htmlExpiresAt = null;
	/** @var array{baseVersion: int, lastSeenAt: int, lastActivityAt: int, expiresAt: int}|null */
	protected ?array $markdownEditorState = null;
	protected bool $markdownEditorReleased = false;
	protected bool $htmlUnifiedLinkValidated = false;

	public function __construct(int $objectId, int $attachedObjectId = null, int $externalLinkId = null)
	{
		$this->attachedObjectId = $attachedObjectId;
		$this->objectId = $objectId;
		$this->externalLinkId = $externalLinkId;
	}

	public static function tryBuildByAttachedObject(?AttachedObject $attachedObject, File $file): self
	{
		if ($attachedObject)
		{
			return self::buildByAttachedObject($attachedObject);
		}

		return self::buildByFile($file);
	}

	public static function buildByAttachedObject(AttachedObject $attachedObject): self
	{
		if (!($attachedObject->getFile() instanceof File))
		{
			throw new ArgumentException('Attached object model doesn\'t have file.');
		}

		$context = new self(
			$attachedObject->getFile()->getRealObjectId(),
			$attachedObject->getId()
		);

		$context
			->setObject($attachedObject->getFile())
			->setAttachedObject($attachedObject)
		;

		return $context;
	}

	public static function buildByFile(File $file): self
	{
		$context = new self(
			$file->getRealObjectId()
		);

		$context->setObject($file);

		return $context;
	}

	public static function buildByExternalLink(ExternalLink $externalLink): self
	{
		$context = new self(
			$externalLink->getObject()->getRealObjectId(),
			null,
			$externalLink->getId()
		);

		$context->setObject($externalLink->getObject());

		return $context;
	}

	public static function buildByObject(BaseObject $baseObject): self
	{
		if (!($baseObject instanceof File))
		{
			throw new ArgumentTypeException('baseObject', File::class);
		}

		return self::buildByFile($baseObject);
	}

	public static function buildFromJson(string $json): ?self
	{
		$decoded = Json::decode($json);
		if (!$decoded)
		{
			return null;
		}

		$context = (new self(
			$decoded['objectId'],
			$decoded['attachedObjectId'] ?? null,
			$decoded['externalLinkId'] ?? null
		))->setHtmlEditorState(
			isset($decoded['html']['baselineVersion']) ? (int)$decoded['html']['baselineVersion'] : null,
			isset($decoded['html']['expiresAt']) ? (int)$decoded['html']['expiresAt'] : null,
		)->setHtmlUnifiedLinkValidated(($decoded['html']['unifiedLinkValidated'] ?? false) === true);

		$markdown = $decoded['md'] ?? null;
		if (
			isset(
				$markdown['baseVersion'],
				$markdown['lastSeenAt'],
				$markdown['lastActivityAt'],
				$markdown['expiresAt'],
			)
		)
		{
			$context->setMarkdownEditorState(
				(int)$markdown['baseVersion'],
				(int)$markdown['lastSeenAt'],
				(int)$markdown['lastActivityAt'],
				(int)$markdown['expiresAt'],
			);
			if (($markdown['released'] ?? false) === true)
			{
				$context->markMarkdownEditorReleased();
			}
		}

		return $context;
	}

	public function toJson($options = 0)
	{
		$data = [
			'attachedObjectId' => $this->getAttachedObjectId(),
			'objectId' => $this->getObjectId(),
			'externalLinkId' => $this->getExternalLinkId(),
		];
		if ($this->htmlBaselineVersion !== null && $this->htmlExpiresAt !== null)
		{
			$data['html'] = [
				'baselineVersion' => $this->htmlBaselineVersion,
				'expiresAt' => $this->htmlExpiresAt,
				'unifiedLinkValidated' => $this->htmlUnifiedLinkValidated,
			];
		}
		if ($this->markdownEditorState !== null)
		{
			$data['md'] = $this->markdownEditorState;
			if ($this->markdownEditorReleased)
			{
				$data['md']['released'] = true;
			}
		}

		return Json::encode($data, $options);
	}

	public function setHtmlEditorState(?int $baselineVersion, ?int $expiresAt): self
	{
		$this->htmlBaselineVersion = $baselineVersion;
		$this->htmlExpiresAt = $expiresAt;

		return $this;
	}

	public function getHtmlBaselineVersion(): ?int
	{
		return $this->htmlBaselineVersion;
	}

	public function getHtmlExpiresAt(): ?int
	{
		return $this->htmlExpiresAt;
	}

	public function setMarkdownEditorState(int $baseVersion, int $lastSeenAt, int $lastActivityAt, int $expiresAt): self
	{
		$this->markdownEditorState = [
			'baseVersion' => $baseVersion,
			'lastSeenAt' => $lastSeenAt,
			'lastActivityAt' => $lastActivityAt,
			'expiresAt' => $expiresAt,
		];

		return $this;
	}

	/**
	 * @return array{baseVersion: int, lastSeenAt: int, lastActivityAt: int, expiresAt: int}|null
	 */
	public function getMarkdownEditorState(): ?array
	{
		return $this->markdownEditorState;
	}

	/**
	 * The md session was left by its tab on purpose (release): it is never taken again, unlike one put out by
	 * a deadline.
	 */
	public function markMarkdownEditorReleased(): self
	{
		$this->markdownEditorReleased = true;

		return $this;
	}

	public function isMarkdownEditorReleased(): bool
	{
		return $this->markdownEditorReleased;
	}

	public function setHtmlUnifiedLinkValidated(bool $validated): self
	{
		$this->htmlUnifiedLinkValidated = $validated;

		return $this;
	}

	public function isHtmlUnifiedLinkValidated(): bool
	{
		return $this->htmlUnifiedLinkValidated;
	}

	public function getAttachedObjectId(): ?int
	{
		return $this->attachedObjectId;
	}

	public function getAttachedObject(): ?AttachedObject
	{
		if (!$this->getAttachedObjectId())
		{
			return null;
		}

		if (!$this->attachedObject)
		{
			$this->attachedObject = AttachedObject::loadById($this->getAttachedObjectId(), ['OBJECT']);
			if (!$this->attachedObject)
			{
				$this->attachedObjectId = null;

				return null;
			}

			$this->setObject($this->attachedObject->getFile());
		}

		return $this->attachedObject;
	}

	public function getExternalLinkId(): ?int
	{
		return $this->externalLinkId;
	}

	public function getExternalLink(): ?ExternalLink
	{
		if (!$this->getExternalLinkId())
		{
			return null;
		}

		if (!$this->externalLink)
		{
			$this->externalLink = ExternalLink::loadById($this->getExternalLinkId());
		}

		return $this->externalLink;
	}

	public function getObjectId(): int
	{
		return $this->objectId;
	}

	public function getObject(): ?File
	{
		if (!$this->object)
		{
			$this->object = File::loadById($this->getObjectId());
		}

		return $this->object;
	}

	public function getFile(): ?File
	{
		return $this->getObject();
	}

	protected function setAttachedObject(AttachedObject $attachedObject): self
	{
		$this->attachedObject = $attachedObject;

		return $this;
	}

	protected function setObject(File $object): self
	{
		$this->object = $object;

		return $this;
	}
}
