<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled;

use Bitrix\Im\Model\ScheduledMessageTable;
use Bitrix\Im\V2\AccessCheckable;
use Bitrix\Im\V2\ActiveRecord;
use Bitrix\Im\V2\Common\ActiveRecordImplementation;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message\Scheduled\Error\ScheduledMessageError;
use Bitrix\Im\V2\Rest\RestEntity;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Type\DateTime;

class ScheduledMessage implements AccessCheckable, ActiveRecord, RestEntity
{
	use ActiveRecordImplementation;
	use ContextCustomer;

	protected ?int $id = null;
	protected ?int $chatId = null;
	protected ?int $authorId = null;
	protected ?string $uuid = null;
	protected ?string $message = null;
	protected ?DateTime $sendAt = null;
	protected string $status = ScheduledMessageStatus::Scheduled->value;

	public function __construct($source = null)
	{
		$this->initByDefault();

		if (!empty($source))
		{
			$this->load($source);
		}
	}

	public function getPrimaryId(): ?int
	{
		return $this->id;
	}

	public function setPrimaryId(int $primaryId): self
	{
		$this->id = $primaryId;

		return $this;
	}

	public static function getDataClass(): string
	{
		return ScheduledMessageTable::class;
	}

	public static function getRestEntityName(): string
	{
		return 'scheduledMessage';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'id' => $this->getId(),
			'chatId' => $this->getChatId(),
			'authorId' => $this->getAuthorId(),
			'uuid' => $this->getUuid(),
			'message' => $this->getMessage(),
			'sendAt' => $this->getSendAt()?->format('c'),
			'status' => $this->getStatus(),
		];
	}

	public function checkAccess(?int $userId = null): Result
	{
		$result = new Result();

		$contextUserId = $userId ?? $this->getContext()->getUserId();
		$ownerId = $this->getAuthorId();

		if (!$contextUserId || !$ownerId || $ownerId !== $contextUserId)
		{
			return $result->addError(new ScheduledMessageError(ScheduledMessageError::ACCESS_DENIED));
		}

		return $result;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getChatId(): ?int
	{
		return $this->chatId;
	}

	public function setChatId(?int $chatId): self
	{
		$this->chatId = $chatId;

		return $this;
	}

	public function getAuthorId(): ?int
	{
		return $this->authorId;
	}

	public function setAuthorId(?int $authorId): self
	{
		$this->authorId = $authorId;

		return $this;
	}

	public function getUuid(): ?string
	{
		return $this->uuid;
	}

	public function setUuid(?string $uuid): self
	{
		$this->uuid = $uuid;

		return $this;
	}

	public function getMessage(): ?string
	{
		return $this->message;
	}

	public function setMessage(?string $message): self
	{
		$this->message = $message;

		return $this;
	}

	public function getSendAt(): ?DateTime
	{
		return $this->sendAt;
	}

	public function setSendAt(?DateTime $sendAt): self
	{
		$this->sendAt = $sendAt;

		return $this;
	}

	public function getStatus(): string
	{
		return $this->status;
	}

	protected function setStatus(string $status): self
	{
		$this->status = $status;

		return $this;
	}

	public function markScheduled(): self
	{
		return $this->setStatus(ScheduledMessageStatus::Scheduled->value);
	}

	public function markFailed(): self
	{
		return $this->setStatus(ScheduledMessageStatus::Failed->value);
	}

	/**
	 * @return array<array>
	 */
	protected static function mirrorDataEntityFields(): array
	{
		return [
			'ID' => [
				'primary' => true,
				'field' => 'id',
				'set' => 'setId',
				'get' => 'getId',
			],
			'CHAT_ID' => [
				'field' => 'chatId',
				'set' => 'setChatId',
				'get' => 'getChatId',
			],
			'AUTHOR_ID' => [
				'field' => 'authorId',
				'set' => 'setAuthorId',
				'get' => 'getAuthorId',
			],
			'UUID' => [
				'field' => 'uuid',
				'set' => 'setUuid',
				'get' => 'getUuid',
			],
			'MESSAGE' => [
				'field' => 'message',
				'set' => 'setMessage',
				'get' => 'getMessage',
				'nullable' => true,
			],
			'SEND_AT' => [
				'field' => 'sendAt',
				'set' => 'setSendAt',
				'get' => 'getSendAt',
			],
			'STATUS' => [
				'field' => 'status',
				'set' => 'setStatus',
				'get' => 'getStatus',
			],
		];
	}
}
