<?php

namespace Bitrix\Mail\Internals\Search;

use Bitrix\Mail\Internals\Search\Conversion\ConversionFactory;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Text\Emoji;

class IndexBuilder
{
	private ConversionFactory $conversionFactory;

	public function __construct()
	{
		$this->conversionFactory = new ConversionFactory();
	}

	public function build(int $mailboxId): ?string
	{
		$indexData = $this->getDataForIndex($mailboxId);

		if (empty($indexData))
		{
			return null;
		}

		return $this->buildFromData($indexData);
	}

	/**
	 * @param $mailboxesData array<array{
	 *     ID: int,
	 *     USER_ID: int,
	 *     EMAIL: string,
	 *     NAME: string,
	 * }>
	 * @return array{MAILBOX_ID: string}
	 */
	public function buildBatch(array $mailboxesData): array
	{
		$result = [];
		$fieldsMap = $this->getFieldsMap();

		foreach ($mailboxesData as $mailboxData)
		{
			if (empty($mailboxData['ID']))
			{
				continue;
			}

			$mailboxId = (int)$mailboxData['ID'];

			$dataToIndex = [
				FieldDictionary::FIELD_USER_ID => $mailboxData[$fieldsMap[FieldDictionary::FIELD_USER_ID]] ?? null,
				FieldDictionary::FIELD_MAILBOX_EMAIL => $mailboxData[$fieldsMap[FieldDictionary::FIELD_MAILBOX_EMAIL]] ?? null,
				FieldDictionary::FIELD_MAILBOX_NAME => $mailboxData[$fieldsMap[FieldDictionary::FIELD_MAILBOX_NAME]] ?? null,
			];

			$index = $this->buildFromData(array_filter($dataToIndex, fn($value) => !is_null($value)));

			if (!is_null($index))
			{
				$result[] = [
					'MAILBOX_ID' => $mailboxId,
					'SEARCH_INDEX' => $index,
				];
			}
		}

		return $result;
	}

	private function buildFromData(array $indexData): ?string
	{
		if (empty($indexData))
		{
			return null;
		}

		$rawIndex = $this->buildRawIndex($indexData);
		if (is_null($rawIndex))
		{
			return null;
		}

		$index = $this->convertSpecialCharacters($rawIndex);
		$index = $this->encodeEmoji($index);
		$index = $this->makeUnique($index);
		$index = $this->moveCharacters($index);

		return $index;
	}

	private function buildRawIndex(array $indexData): ?string
	{
		$index = '';

		foreach ($indexData as $fieldName => $fieldData)
		{
			$converter = $this->conversionFactory->getConverterByField($fieldName);

			$convertedValue = $converter->convert($fieldData);
			if (is_null($convertedValue))
			{
				return null;
			}

			$index .= sprintf(' %s ', $convertedValue);
		}

		return $index;
	}

	private function makeUnique(string $index): string
	{
		$fields = explode(' ', $index);
		$fields = array_unique($fields);

		return implode(' ', array_filter($fields));
	}

	private function convertSpecialCharacters(string $index): string
	{
		if (Loader::includeModule('search'))
		{
			$index = (string)\CSearch::killTags($index);
		}

		return mb_strtoupper(trim(str_replace(["\r", "\n", "\t"], ' ', $index)));
	}

	private function encodeEmoji(string $index): string
	{
		return Emoji::encode($index);
	}

	private function moveCharacters(string $index): string
	{
		return str_rot13($index);
	}

	private function getDataForIndex(int $mailboxId): ?array
	{
		$mailbox =
			MailboxTable::query()
				->setSelect(['USER_ID', 'EMAIL', 'NAME'])
				->where('ID', $mailboxId)
				->fetch()
		;

		if (empty($mailbox))
		{
			return null;
		}

		$fieldsMap = $this->getFieldsMap();

		return [
			FieldDictionary::FIELD_USER_ID => $mailbox[$fieldsMap[FieldDictionary::FIELD_USER_ID]],
			FieldDictionary::FIELD_MAILBOX_EMAIL => $mailbox[$fieldsMap[FieldDictionary::FIELD_MAILBOX_EMAIL]],
			FieldDictionary::FIELD_MAILBOX_NAME => $mailbox[$fieldsMap[FieldDictionary::FIELD_MAILBOX_NAME]],
		];
	}

	private function getFieldsMap(): array
	{
		return [
			FieldDictionary::FIELD_USER_ID => 'USER_ID',
			FieldDictionary::FIELD_MAILBOX_EMAIL => 'EMAIL',
			FieldDictionary::FIELD_MAILBOX_NAME => 'NAME',
		];
	}
}
