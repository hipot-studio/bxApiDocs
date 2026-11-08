<?php

namespace Bitrix\Crm\WebForm\Agreement;

use Bitrix\Main\UserConsent\Agreement as MainAgreement;
use Bitrix\Main\UserConsent\Internals\AgreementTable;
use Bitrix\Main\UserConsent\Internals\FieldTable;

/** Loads the current documents for one form configuration without retaining them across calls. */
final class Batch
{
	/**
	 * @param int[] $agreementIds
	 * @return MainAgreement[] Indexed by agreement ID, in the requested order.
	 */
	public static function load(array $agreementIds, array $replace = []): array
	{
		$agreementIds = array_values(array_unique(array_map('intval', $agreementIds)));
		if (!$agreementIds)
		{
			return [];
		}

		$rows = [];
		$standardIds = [];
		foreach (AgreementTable::getList(['filter' => ['@ID' => $agreementIds]]) as $row)
		{
			$rows[$row['ID']] = $row;
			if ($row['TYPE'] === MainAgreement::TYPE_STANDARD)
			{
				$standardIds[] = $row['ID'];
			}
		}

		$fieldValues = [];
		if ($standardIds)
		{
			foreach (FieldTable::getList(['filter' => ['@AGREEMENT_ID' => $standardIds]]) as $field)
			{
				$fieldValues[$field['AGREEMENT_ID']][$field['CODE']] = $field['VALUE'];
			}
		}

		$result = [];
		foreach ($agreementIds as $id)
		{
			if (isset($rows[$id]))
			{
				$result[$id] = new PreloadedAgreement($rows[$id], $fieldValues[$id] ?? [], $replace);
			}
		}

		return $result;
	}
}
