<?php

namespace Bitrix\Crm\Timeline\SignDocument;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Item;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;

final class DocumentData implements \JsonSerializable, Arrayable
{
	private const TYPE_COMPANY = 'company';
	private const TYPE_EMPLOYEE = 'employee';

	protected int $documentId;
	protected ?string $documentHash = null;
	protected ?string $memberHash = null;
	protected ?DateTime $createdTime = null;
	protected ?int $authorId = null;
	/** @var ItemIdentifier[] */
	protected array $bindings = [];
	protected ?Uri $fileUrl = null;
	protected ?Signer $mySigner = null;
	/** @var Signer[] */
	protected array $signers = [];
	protected ?Item $item = null;
	protected ?int $fieldsCount = null;
	protected ?int $initiatorUserId = null;
	protected string $initiatedByType = self::TYPE_COMPANY;
	protected ?int $annulledMembersCount = null;
	protected ?string $cancelReason = null;
	protected ?int $processingTaskId = null;
	protected ?string $processingTaskTitle = null;

	public function __construct(int $documentId)
	{
		$this->documentId = $documentId;
	}

	public static function createFromArray(array $data): self
	{
		$eventData = new self((int)$data['documentId']);
		if (!empty($data['createdTime']))
		{
			$createdTime = $data['createdTime'];
			if (!($createdTime instanceof DateTime))
			{
				$createdTime = new DateTime($createdTime);
			}
			$eventData->setCreatedTime($createdTime);
		}
		if (!empty($data['authorId']))
		{
			$eventData->setAuthorId((int)$data['authorId']);
		}
		if (!empty($data['memberHash']))
		{
			$eventData->setMemberHash($data['memberHash']);
		}
		if (!empty($data['documentHash']))
		{
			$eventData->setDocumentHash($data['documentHash']);
		}

		if (is_string($data['initiatedByType'] ?? null))
		{
			$eventData->setInitiatedByType($data['initiatedByType']);
		}

		if (!empty($data['bindings']) && is_array($data['bindings']))
		{
			foreach ($data['bindings'] as $binding)
			{
				if (is_array($binding))
				{
					$binding = ItemIdentifier::createFromArray($binding);
				}
				if ($binding instanceof ItemIdentifier)
				{
					$eventData->addBinding($binding);
				}
			}
		}
		if (!empty($data['fileUrl']))
		{
			$fileUrl = $data['fileUrl'];
			if (!($fileUrl instanceof Uri))
			{
				$fileUrl = new Uri($fileUrl);
			}
			$eventData->setFileUrl($fileUrl);
		}
		if (!empty($data['mySigner']))
		{
			$mySigner = $data['mySigner'];
			if (is_array($mySigner))
			{
				$mySigner = Signer::createFromArray($data['mySigner']);
			}
			if ($mySigner instanceof Signer)
			{
				$eventData->setMySigner($mySigner);
			}
		}
		if (!empty($data['signers']) && is_array($data['signers']))
		{
			foreach ($data['signers'] as $signer)
			{
				if (is_array($signer))
				{
					$signer = Signer::createFromArray($signer);
				}
				if ($signer instanceof Signer)
				{
					$eventData->addSigner($signer);
				}
			}
		}
		if (isset($data['item']) && $data['item'] instanceof Item)
		{
			$eventData->setItem($data['item']);
		}
		if (isset($data['fieldsCount']))
		{
			$eventData->setFieldsCount((int)$data['fieldsCount']);
		}
		$eventData->bindDocumentEntities();
		if (!empty($data['initiatorUserId']) && is_numeric($data['initiatorUserId']))
		{
			$eventData->setInitiatorUserId((int)$data['initiatorUserId']);
		}
		if (isset($data['annulledMembersCount']) && is_numeric($data['annulledMembersCount']))
		{
			$eventData->setAnnulledMembersCount((int)$data['annulledMembersCount']);
		}
		if (is_string($data['cancelReason'] ?? null))
		{
			$eventData->setCancelReason($data['cancelReason']);
		}
		if (isset($data['processingTaskId']) && is_numeric($data['processingTaskId']))
		{
			$eventData->setProcessingTaskId((int)$data['processingTaskId']);
		}
		if (is_string($data['processingTaskTitle'] ?? null))
		{
			$eventData->setProcessingTaskTitle($data['processingTaskTitle']);
		}

		return $eventData;
	}

	private function bindDocumentEntities(): void
	{
		if (!$this->item)
		{
			return;
		}

		$item = $this->item;
		$relatedContacts = $item->getContactIds();
		$relatedCompanies = [];

		if ($item->hasField(Item\Contact::FIELD_NAME_COMPANY_BINDINGS))
		{
			$relatedCompanies = $item->get(Item\Contact::FIELD_NAME_COMPANY_BINDINGS);
		}

		foreach ($relatedContacts as $contact)
		{
			$this->addBinding(
				new ItemIdentifier(\CCrmOwnerType::Contact,  $contact)
			);
		}
		foreach ($relatedCompanies as $company)
		{
			$this->addBinding(
				new ItemIdentifier(\CCrmOwnerType::Company,  $company['ID'])
			);
		}
		if (isset($item->getData()['MYCOMPANY_ID']) && (int)$item->getData()['MYCOMPANY_ID'] > 0)
		{
			$this->addBinding(
				new ItemIdentifier(\CCrmOwnerType::Company,  $item->getData()['MYCOMPANY_ID'])
			);
		}
	}

	public function getDocumentId(): int
	{
		return $this->documentId;
	}

	public function setCreatedTime(DateTime $createdTime): self
	{
		$this->createdTime = $createdTime;

		return $this;
	}

	public function getCreatedTime(): ?DateTime
	{
		return $this->createdTime;
	}

	public function setAuthorId(int $authorId): self
	{
		$this->authorId = $authorId;

		return $this;
	}

	public function getAuthorId(): ?int
	{
		return $this->authorId;
	}

	/**
	 * @param ItemIdentifier[] $bindings
	 * @return DocumentData
	 */
	public function setBindings(array $bindings): self
	{
		$this->bindings = [];
		foreach ($bindings as $binding)
		{
			if ($binding instanceof ItemIdentifier)
			{
				$this->addBinding($binding);
			}
		}

		return $this;
	}

	public function addBinding(ItemIdentifier $binding): self
	{
		$this->bindings[$binding->getHash()] = $binding;

		return $this;
	}

	/**
	 * @return ItemIdentifier[]
	 */
	public function getBindings(): array
	{
		return $this->bindings;
	}

	public function getBindingsArray(): array
	{
		$bindings = [];
		foreach ($this->bindings as $binding)
		{
			$bindings[] = $binding->toArray();
		}

		return $bindings;
	}

	public function setFileUrl(Uri $fileUrl): self
	{
		$this->fileUrl = $fileUrl;

		return $this;
	}

	public function getFileUrl(): ?Uri
	{
		return $this->fileUrl;
	}

	public function getInitiatedByType(): string
	{
		return $this->initiatedByType;
	}

	public function setInitiatedByType(string $initiatedByType): void
	{
		if (!in_array($initiatedByType, [self::TYPE_COMPANY, self::TYPE_EMPLOYEE], true))
		{
			return;
		}

		$this->initiatedByType = $initiatedByType;
	}

	public function setMySigner(Signer $mySigner): self
	{
		$this->mySigner = $mySigner;

		return $this;
	}

	public function getMySigner(): ?Signer
	{
		return $this->mySigner;
	}

	/**
	 * @param array $signers
	 * @return DocumentData
	 */
	public function setSigners(array $signers): self
	{
		$this->signers = [];
		foreach ($signers as $signer)
		{
			if ($signer instanceof Signer)
			{
				$this->addSigner($signer);
			}
		}

		return $this;
	}

	public function addSigner(Signer $signer): self
	{
		$this->signers[] = $signer;

		return $this;
	}

	public function getSigners(): array
	{
		return $this->signers;
	}

	public function setItem(Item $item): self
	{
		$this->item = $item;
		return $this;
	}

	public function getItem(): ?Item
	{
		return $this->item;
	}

	public function setFieldsCount(?int $fieldsCount): DocumentData
	{
		$this->fieldsCount = $fieldsCount;

		return $this;
	}

	public function getFieldsCount(): ?int
	{
		return $this->fieldsCount;
	}

	/**
	 * @return null|string
	 */
	public function getDocumentHash(): ?string
	{
		return $this->documentHash;
	}

	/**
	 * @param string $documentHash
	 * @return DocumentData
	 */
	public function setDocumentHash(string $documentHash): DocumentData
	{
		$this->documentHash = $documentHash;
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getMemberHash(): ?string
	{
		return $this->memberHash;
	}

	/**
	 * @param string $memberHash
	 * @return DocumentData
	 */
	public function setMemberHash(string $memberHash): DocumentData
	{
		$this->memberHash = $memberHash;
		return $this;
	}

	public function setInitiatorUserId(?int $userId): DocumentData
	{
		$this->initiatorUserId = $userId;

		return $this;
	}

	public function getInitiatorUserId(): ?int
	{
		return $this->initiatorUserId;
	}

	/**
	 * Number of signer records annulled (or restored) by a single mass action.
	 * Null on the entries of every other event and on annulment entries written
	 * before the counter existed.
	 */
	public function setAnnulledMembersCount(?int $annulledMembersCount): DocumentData
	{
		$this->annulledMembersCount = $annulledMembersCount;

		return $this;
	}

	public function getAnnulledMembersCount(): ?int
	{
		return $this->annulledMembersCount;
	}

	/**
	 * Reason a representative of the company gave while cancelling a document of an employee.
	 * Typed by a person, so it reaches the page as plain text. Null on every other event.
	 */
	public function setCancelReason(?string $cancelReason): DocumentData
	{
		$this->cancelReason = $cancelReason;

		return $this;
	}

	public function getCancelReason(): ?string
	{
		return $this->cancelReason;
	}

	public function setProcessingTaskId(?int $processingTaskId): DocumentData
	{
		$this->processingTaskId = $processingTaskId;

		return $this;
	}

	public function getProcessingTaskId(): ?int
	{
		return $this->processingTaskId;
	}

	/**
	 * Title the task carried when it was created. Stored with the entry instead of being read
	 * back from the task: a history record states what happened then, so a later rename or a
	 * deleted task leaves the record as it was written.
	 */
	public function setProcessingTaskTitle(?string $processingTaskTitle): DocumentData
	{
		$this->processingTaskTitle = $processingTaskTitle;

		return $this;
	}

	public function getProcessingTaskTitle(): ?string
	{
		return $this->processingTaskTitle;
	}

	public function toArray(): array
	{
		$data = [
			'documentId' => $this->getDocumentId(),
		];
		if ($this->createdTime)
		{
			$data['createdTime'] = $this->createdTime;
		}
		if ($this->authorId)
		{
			$data['authorId'] = $this->authorId;
		}
		if ($this->fileUrl)
		{
			$data['fileUrl'] = $this->fileUrl;
		}
		if ($this->mySigner)
		{
			$data['mySigner'] = $this->mySigner->toArray();
		}
		if ($this->documentHash)
		{
			$data['documentHash'] = $this->documentHash;
		}
		if ($this->memberHash)
		{
			$data['memberHash'] = $this->memberHash;
		}
		$data['bindings'] = $this->getBindingsArray();
		$data['signers'] = [];
		foreach ($this->signers as $signer)
		{
			$data['signers'][] = $signer->toArray();
		}
		if ($this->fieldsCount > 0)
		{
			$data['fieldsCount'] = $this->fieldsCount;
		}

		if ($this->initiatorUserId)
		{
			$data['initiatorUserId'] = $this->initiatorUserId;
		}

		if ($this->annulledMembersCount !== null)
		{
			$data['annulledMembersCount'] = $this->annulledMembersCount;
		}

		if ($this->cancelReason !== null)
		{
			$data['cancelReason'] = $this->cancelReason;
		}

		if ($this->processingTaskId !== null)
		{
			$data['processingTaskId'] = $this->processingTaskId;
		}

		if ($this->processingTaskTitle !== null)
		{
			$data['processingTaskTitle'] = $this->processingTaskTitle;
		}

		return $data;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
