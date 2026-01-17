<?php

namespace Bitrix\Crm\Recurring\Mail;

use Bitrix\Crm\Recurring\Entity\DynamicRecurringDocumentTable;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\ORM\Data\AddResult;

final class DocumentManager
{
	use Singleton;

	private int $entityTypeId;
	private int $entityId;
	private int $documentId;
	private int $recurringItemId;
	private int $emailTemplateId;

	public function setEntityTypeId(int $entityTypeId): self
	{
		$this->entityTypeId = $entityTypeId;

		return $this;
	}

	public function setEntityId(int $entityId): self
	{
		$this->entityId = $entityId;

		return $this;
	}

	public function setDocumentId(int $documentId): self
	{
		$this->documentId = $documentId;

		return $this;
	}

	public function setRecurringItemId(int $recurringItemId): self
	{
		$this->recurringItemId = $recurringItemId;

		return $this;
	}

	public function setEmailTemplateId(int $emailTemplateId): self
	{
		$this->emailTemplateId = $emailTemplateId;

		return $this;
	}

	public function bind(): AddResult
	{
		return DynamicRecurringDocumentTable::add([
			'ENTITY_TYPE_ID' => $this->entityTypeId,
			'ENTITY_ID' => $this->entityId,
			'DOCUMENT_ID' => $this->documentId,
			'RECURRING_ITEM_ID' => $this->recurringItemId,
			'EMAIL_TEMPLATE_ID' => $this->emailTemplateId,
		]);
	}
}
