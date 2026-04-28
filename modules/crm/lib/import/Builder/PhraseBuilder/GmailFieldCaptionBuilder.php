<?php

namespace Bitrix\Crm\Import\Builder\PhraseBuilder;

use Bitrix\Main\Localization\Loc;

final class GmailFieldCaptionBuilder
{
	public const TYPE_LABEL = 'LABEL';
	public const TYPE_VALUE = 'VALUE';

	private ?string $fieldId = null;
	private ?string $type = null;
	private ?int $index = null;

	public function setField(string $fieldId): self
	{
		$this->fieldId = $fieldId;

		return $this;
	}

	public function setType(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function setIndex(string $index): self
	{
		$this->index = $index;

		return $this;
	}

	public function build(): ?string
	{
		/**
		 * CRM_IMPORT_FIELD_GMAIL_EMAIL_LABEL
		 * CRM_IMPORT_FIELD_GMAIL_EMAIL_VALUE
		 *
		 * CRM_IMPORT_FIELD_GMAIL_PHONE_LABEL
		 * CRM_IMPORT_FIELD_GMAIL_PHONE_VALUE
		 *
		 * CRM_IMPORT_FIELD_GMAIL_WEB_LABEL
		 * CRM_IMPORT_FIELD_GMAIL_WEB_VALUE
		 */
		return Loc::getMessage("CRM_IMPORT_FIELD_GMAIL_{$this->fieldId}_{$this->type}", [
			'#INDEX#' => $this->index,
		]);
	}
}
