<?php

namespace Bitrix\Crm\WebForm\Agreement;

use Bitrix\Main\UserConsent\Agreement;

/**
 * Read snapshot used by Batch. The native constructor and rendering keep their replacement,
 * language, provider and HTML handling; only the two database reads use the supplied rows.
 *
 * @internal
 */
final class PreloadedAgreement extends Agreement
{
	public function __construct(
		private readonly array $row,
		private readonly array $fieldValues,
		array $replace = [],
	)
	{
		parent::__construct((int)$row['ID'], $replace);
	}

	public function load($id)
	{
		if ((int)$id !== (int)$this->row['ID'])
		{
			return parent::load($id);
		}

		$this->data = $this->row;
		$this->id = $id;
		$this->intl->load($this->data['LANGUAGE_ID']);

		return true;
	}

	public function getFieldValues()
	{
		if ((int)$this->id !== (int)$this->row['ID'])
		{
			return parent::getFieldValues();
		}

		$result = [];
		foreach ($this->intl->getFields() as $field)
		{
			$result[$field['CODE']] = $this->fieldValues[$field['CODE']] ?? '';
		}

		return $result;
	}
}
