<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Param;

use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\V2\Message\Param;
use Bitrix\Im\V2\Result;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;

/**
 * Ids of the files of a message in the order they are shown, written only on the edit path. The composition itself
 * stays in FILE_ID: this parameter answers for the sequence alone and its absence means the previous behaviour.
 *
 * The row is saved past the common collection of the params, see prepareFields().
 */
class FileOrder extends Param
{
	protected ?string $type = Param::TYPE_JSON;

	public function setValue($value): self
	{
		$fileIds = self::toFileIds($value);
		if ($fileIds === [])
		{
			return $this->unsetValue();
		}

		$this->value = $fileIds;
		$this->jsonValue = Json::encode($fileIds);
		$this->markChanged();

		return $this;
	}

	public function loadJsonFilter($value)
	{
		$this->value = null;

		if (empty($value))
		{
			return $value;
		}

		try
		{
			$this->value = self::toFileIds(Json::decode($value)) ?: null;
		}
		catch (ArgumentException)
		{
		}

		return $value;
	}

	/**
	 * Declines the save of the common collection and writes the row itself: the storage of the params has no unique
	 * key, and a scalar parameter read from two rows takes the last one, so the row is found by the pair
	 * "message plus name of the parameter" and updated in place instead of being inserted blindly.
	 */
	public function prepareFields(): Result
	{
		$result = (new Result())->setData(['SKIP_SAVE' => true]);

		if (!$this->isChanged() || !$this->getMessageId())
		{
			return $result;
		}

		$writeResult = $this->write();
		if (!$writeResult->isSuccess())
		{
			return $writeResult;
		}

		$this->markChanged(false);

		return $result;
	}

	private function write(): Result
	{
		$result = new Result();

		$existingIds = $this->getStoredRowIds();
		$primaryId = array_shift($existingIds);

		$fields = [
			'PARAM_VALUE' => '',
			'PARAM_JSON' => $this->getJsonValue(),
		];

		if ($primaryId === null)
		{
			$addResult = MessageParamTable::add(
				$fields + ['MESSAGE_ID' => $this->getMessageId(), 'PARAM_NAME' => $this->getName()],
			);
			if (!$addResult->isSuccess())
			{
				return $result->addErrors($addResult->getErrors());
			}

			$this->setParamId((int)$addResult->getId());

			return $result;
		}

		$updateResult = MessageParamTable::update($primaryId, $fields);
		if (!$updateResult->isSuccess())
		{
			return $result->addErrors($updateResult->getErrors());
		}

		$this->setParamId($primaryId);

		if (!empty($existingIds))
		{
			MessageParamTable::deleteBatch(['=ID' => $existingIds]);
		}

		return $result;
	}

	/**
	 * @return int[]
	 */
	private function getStoredRowIds(): array
	{
		$rows = MessageParamTable::query()
			->setSelect(['ID'])
			->where('MESSAGE_ID', $this->getMessageId())
			->where('PARAM_NAME', $this->getName())
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * @return int[]
	 */
	private static function toFileIds(mixed $value): array
	{
		$numeric = array_filter((array)$value, 'is_numeric');

		return array_values(array_map('intval', $numeric));
	}
}
