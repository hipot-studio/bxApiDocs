<?php

namespace Bitrix\Crm\Import\Builder\PhraseBuilder;

use Bitrix\Main\Localization\Loc;

final class MultifieldCaptionBuilder
{
	private string $fieldId;
	private string $type;
	private int $index;

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

	public function setIndex(int $index): self
	{
		$this->index = $index;

		return $this;
	}

	public function build(): string
	{
		/**
		 * CRM_IMPORT_MULTIFIELD_CAPTION_EMAIL_WORK
		 * CRM_IMPORT_MULTIFIELD_CAPTION_EMAIL_HOME
		 * CRM_IMPORT_MULTIFIELD_CAPTION_EMAIL_MAILING
		 * CRM_IMPORT_MULTIFIELD_CAPTION_EMAIL_OTHER
		 *
		 * CRM_IMPORT_MULTIFIELD_CAPTION_PHONE_MOBILE
		 * CRM_IMPORT_MULTIFIELD_CAPTION_PHONE_WORK
		 * CRM_IMPORT_MULTIFIELD_CAPTION_PHONE_FAX
		 * CRM_IMPORT_MULTIFIELD_CAPTION_PHONE_HOME
		 * CRM_IMPORT_MULTIFIELD_CAPTION_PHONE_PAGER
		 * CRM_IMPORT_MULTIFIELD_CAPTION_PHONE_MAILING
		 * CRM_IMPORT_MULTIFIELD_CAPTION_PHONE_OTHER
		 *
		 * CRM_IMPORT_FIELD_OUTLOOK_WEB_HOME
		 */
		return Loc::getMessage("CRM_IMPORT_MULTIFIELD_CAPTION_{$this->fieldId}_{$this->type}", [
			'#INDEX#' => $this->index,
		]);
	}
}
