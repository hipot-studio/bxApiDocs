<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\AgreementItem;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\Agreements;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\WebForm\Internals\AgreementTable;
use Bitrix\Main\Result;
use Bitrix\Main\UserConsent;

/**
 * Writes the agreements of a CRM form, mirroring AgreementSectionLoader.
 *
 * The collection is rewritten as a whole and an agreement is bound to the form once: of several
 * entries carrying the same agreement the first one is stored whole and the rest are dropped.
 * Legacy deduplicated twice with opposite outcomes, and neither is reproduced: its write path kept
 * the position of the first entry and the flags of the last one, mixing two entries of the input
 * into one row without a word. Here one entry answers for the row, so the stored flags and the
 * stored order come from the same place - the first appearance, the order the read layer publishes.
 *
 * The rows are rewritten, so an attribute the input leaves out is read off the row the form already
 * holds for that agreement: unspecified means unchanged here as it does in every other collection.
 *
 * The flag enabling the section is a column of the form row: FormWriteRepository writes it.
 *
 * @internal
 */
final class AgreementSectionWriter extends WebFormSectionWriter
{
	private const ITEMS_PATH = WebForm::SECTION_AGREEMENTS . '.' . Agreements::FIELD_ITEMS;

	/**
	 * @param class-string<AgreementTable> $agreementTable
	 * @param class-string<UserConsent\Internals\AgreementTable> $documentTable
	 */
	public function __construct(
		private readonly string $agreementTable = AgreementTable::class,
		private readonly string $documentTable = UserConsent\Internals\AgreementTable::class,
	)
	{
	}

	public function getSectionName(): string
	{
		return WebForm::SECTION_AGREEMENTS;
	}

	protected function writeTouched(int $formId, WebFormChangeSet $changeSet): Result
	{
		if (!$changeSet->has(self::ITEMS_PATH))
		{
			return new Result();
		}

		/** @var AgreementItem[] $items */
		$items = $changeSet->get(self::ITEMS_PATH);
		$storedFlags = $this->loadStoredFlags($formId, $items);

		self::deleteFormRows($this->agreementTable, $formId);

		$result = new Result();
		$writtenAgreementIds = [];
		foreach ($items as $item)
		{
			$agreementId = (int)$item->agreementId;
			if (isset($writtenAgreementIds[$agreementId]))
			{
				continue;
			}

			$writtenAgreementIds[$agreementId] = true;
			$addResult = ($this->agreementTable)::add(
				self::createRow($item, $formId, $storedFlags[$agreementId] ?? null),
			);
			if (!self::copyErrors($addResult, $result))
			{
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Flags the form already keeps for the agreements the input names, by agreement.
	 *
	 * The rows of the section are removed and written anew, because an agreement of a form has no
	 * identity of its own beyond the agreement it points at. An attribute the input leaves out would
	 * therefore take the creation default of the column instead of keeping what is stored - a
	 * checked, optional agreement sent back as its identifier alone would come out unchecked and
	 * required. So the flags are read before the removal and stand in for what the input does not say.
	 *
	 * Acceptance by submit is not exposed by this API, so its stored value is always read.
	 *
	 * @param AgreementItem[] $items
	 * @return array<int, array{CHECKED: string, REQUIRED: string, ACCEPT_BY_SUBMIT: string}>
	 */
	private function loadStoredFlags(int $formId, array $items): array
	{
		$askedIds = [];
		foreach ($items as $item)
		{
			$agreementId = (int)$item->agreementId;
			if ($agreementId > 0)
			{
				$askedIds[$agreementId] = true;
			}
		}

		if ($formId <= 0 || $askedIds === [])
		{
			return [];
		}

		$rows = ($this->agreementTable)::query()
			->setSelect(['AGREEMENT_ID', 'CHECKED', 'REQUIRED', 'ACCEPT_BY_SUBMIT'])
			->where('FORM_ID', $formId)
			->whereIn('AGREEMENT_ID', array_keys($askedIds))
			->fetchAll()
		;

		$submitAgreementIds = [];
		foreach ($rows as $row)
		{
			if ($row['ACCEPT_BY_SUBMIT'] === 'Y')
			{
				$submitAgreementIds[] = (int)$row['AGREEMENT_ID'];
			}
		}

		$allowedIds = [];
		if ($submitAgreementIds !== [])
		{
			$documents = ($this->documentTable)::query()
				->setSelect(['ID'])
				->whereIn('ID', $submitAgreementIds)
				->where('ACTIVE', 'Y')
				->fetchAll()
			;
			$allowedIds = array_fill_keys(array_column($documents, 'ID'), true);
		}

		$flags = [];
		foreach ($rows as $row)
		{
			$flags[(int)$row['AGREEMENT_ID']] ??= [
				'CHECKED' => (string)$row['CHECKED'],
				'REQUIRED' => (string)$row['REQUIRED'],
				'ACCEPT_BY_SUBMIT' => self::toFlag(
					$row['ACCEPT_BY_SUBMIT'] === 'Y' && isset($allowedIds[(int)$row['AGREEMENT_ID']]),
				),
			];
		}

		return $flags;
	}

	/**
	 * Builds the columns the agreement specifies. An unspecified attribute keeps the value the form
	 * already holds for this agreement and takes the creation default of the column only when the
	 * form does not hold it at all.
	 *
	 * @param null|array{CHECKED: string, REQUIRED: string, ACCEPT_BY_SUBMIT: string} $storedFlags
	 * @return array<string, mixed>
	 */
	private static function createRow(AgreementItem $item, int $formId, ?array $storedFlags): array
	{
		$row = [
			'FORM_ID' => $formId,
			'ACCEPT_BY_SUBMIT' => $storedFlags['ACCEPT_BY_SUBMIT'] ?? 'N',
		];

		if ($item->agreementId !== null)
		{
			$row['AGREEMENT_ID'] = $item->agreementId;
		}

		$checked = $item->checked === null ? ($storedFlags['CHECKED'] ?? null) : self::toFlag($item->checked);
		if ($checked !== null)
		{
			$row['CHECKED'] = $checked;
		}

		$required = $item->required === null ? ($storedFlags['REQUIRED'] ?? null) : self::toFlag($item->required);
		if ($required !== null)
		{
			$row['REQUIRED'] = $required;
		}

		return $row;
	}
}
