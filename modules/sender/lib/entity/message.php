<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sender
 * @copyright 2001-2012 Bitrix
 */

namespace Bitrix\Sender\Entity;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sender\Internals\Model\MessageFieldTable;
use Bitrix\Sender\Internals\Model\MessageTable;
use Bitrix\Sender\Internals\Model\MessageUtmTable;
use Bitrix\Sender\Message\Configuration;
use Bitrix\Sender\Message\Result;

Loc::loadMessages(__FILE__);

/**
 * Class Message
 *
 * @package Bitrix\Sender\Entity
 */
class Message extends Base
{
	private const PERSONALIZABLE_FIELD_CODES = ['MESSAGE', 'SUBJECT', 'TITLE'];
	private const PERSONALIZE_CODE_SUFFIX = '_PERSONALIZE';

	/**
	 * Load configuration.
	 *
	 * @param integer|null $id ID.
	 * @param Configuration $configuration Configuration.
	 * @return Configuration
	 */
	public function loadConfiguration($id = null, Configuration $configuration = null)
	{
		if (!$configuration)
		{
			$configuration = new Configuration;
		}

		if ($id && $this->load($id))
		{
			$data = $this->getFields();
			foreach ($configuration->getOptions() as $option)
			{
				$key = $option->getCode();
				$value = isset($data[$key]) ? $data[$key] : null;
				if ($option->getType() === $option::TYPE_FILE)
				{
					$value = ($value <> '') ? explode(',', $value) : $value;
				}

				$configuration->set($key, $value);
			}

			$configuration->setId($id);
		}

		return $configuration;
	}

	/**
	 * Save configuration.
	 *
	 * @param Configuration $configuration Configuration.
	 * @return \Bitrix\Main\Result
	 */
	public function saveConfiguration(Configuration $configuration)
	{
		$this->setId($configuration->getId());
		$result = $configuration->checkOptions();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = [];
		foreach ($configuration->getOptions() as $option)
		{
			$value = $option->getValue();
			if ($option->getType() === $option::TYPE_FILE)
			{
				$value = is_array($value) ? implode(',', $value) : $value;
			}

			$data[] = [
				'CODE' => $option->getCode(),
				'TYPE' => $option->getType(),
				'VALUE' => $value,
			];
		}

		if (count($data) == 0)
		{
			$result->addError(new Error('No options.'));
		}

		$this->setFields($data)->save();

		if ($this->hasErrors())
		{
			$result->addErrors($this->errors->toArray());
		}
		else
		{
			$configuration->setId($this->getId());
		}

		return $result;
	}

	/**
	 * Copy configuration.
	 *
	 * @param integer|string|null $id ID.
	 * @return Result|null
	 */
	public function copyConfiguration($id)
	{
		$copiedId = $this->copyData($id);
		$result = new Result();
		$result->setId($copiedId);

		return $result;
	}

	/**
	 * Remove configuration.
	 *
	 * @param integer $id ID.
	 * @return bool
	 */
	public function removeConfiguration($id)
	{
		$result = static::removeById($id);
		return $result->isSuccess();
	}

	/**
	 * Remove by ID.
	 *
	 * @param integer $id ID.
	 * @return \Bitrix\Main\Result
	 */
	public static function removeById($id)
	{
		return MessageTable::delete($id);
	}

	/**
	 * Get fields.
	 *
	 * @return array
	 */
	public function getFields(): array
	{
		$result = [];
		$data = $this->getData();
		foreach ($data['FIELDS'] as $field)
		{
			$result[$field['CODE']] = $field['VALUE'];
		}

		return $result;
	}

	/**
	 * Set fields.
	 *
	 * @param array $fields Fields.
	 * @return $this
	 */
	public function setFields(array $fields)
	{
		$this->set('FIELDS', $fields);
		return $this;
	}

	/**
	 * Get fields.
	 *
	 * @return array
	 */
	public function getUtm(): array
	{
		$result = [];
		$data = $this->getData();
		foreach ($data['UTM'] as $field)
		{
			$result[$field['CODE']] = $field['VALUE'];
		}

		return $result;
	}

	/**
	 * Set fields.
	 *
	 * @param array $utm
	 * @return $this
	 */
	public function setUtm(array $utm): Message
	{
		$this->set('UTM', $utm);
		return $this;
	}

	/**
	 * Get code.
	 *
	 * @return ?string
	 */
	public function getCode(): ?string
	{
		return $this->get('CODE');
	}

	/**
	 * Set code.
	 *
	 * @param string $code Code.
	 * @return $this
	 */
	public function setCode(string $code)
	{
		return $this->set('CODE', $code);
	}

	/**
	 * Get default data.
	 *
	 * @return array
	 */
	protected function getDefaultData()
	{
		return [
			'CODE' => '',
			'FIELDS' => [],
			'UTM' => [],
		];
	}

	/**
	 * Load data.
	 *
	 * @param integer $id ID.
	 * @return array|null
	 */
	protected function loadData($id)
	{
		$data = MessageTable::getRowById($id);
		if (!is_array($data))
		{
			return null;
		}
		if ($this->getCode() && $this->getCode() != $data['CODE'])
		{
			return null;
		}

		$data['FIELDS'] = [];
		$fieldsDb = MessageFieldTable::getList([
			'select' => ['TYPE', 'CODE', 'VALUE'],
			'filter' => [
				'=MESSAGE_ID' => $id,
			],
		]);
		while ($field = $fieldsDb->fetch())
		{
			$data['FIELDS'][] = $field;
		}

		return $data;
	}

	protected function parsePersonalizeList($text)
	{
	}

	/**
	 * Save data.
	 *
	 * @param integer|null $id ID.
	 * @param array $data Data.
	 * @return integer|null
	 */
	protected function saveData($id, array $data)
	{
		$fields = $data['FIELDS'];
		$utmTags = $data['UTM'];
		unset($data['FIELDS']);
		unset($data['UTM']);

		if (!is_array($fields) && count($fields) === 0)
		{
			$this->addError('No message fields.');
			return $id;
		}

		$id = $this->saveByEntity(MessageTable::getEntity(), $id, $data);
		if ($this->hasErrors())
		{
			return $id;
		}

		$this->replaceRowsByCode(MessageFieldTable::class, $id, $this->buildFieldRows($id, $fields));
		if ($this->hasErrors())
		{
			return $id;
		}

		$this->replaceRowsByCode(MessageUtmTable::class, $id, $this->buildUtmRows($id, $utmTags));

		return $id;
	}

	/**
	 * Brings the child rows of the message to exactly the given set: rows with codes outside the set
	 * are deleted, the rest are inserted or overwritten. Merge keeps the write idempotent, so a row
	 * left by a failed delete or by a parallel save does not break the save with a duplicate key.
	 * A failed row does not stop the rest: every failure goes to the entity errors.
	 *
	 * @param class-string<\Bitrix\Main\Entity\DataManager> $tableClass Table class with
	 * (MESSAGE_ID, CODE) as its primary key, using \Bitrix\Main\ORM\Data\AddStrategy\Trait\AddMergeTrait.
	 * @param integer $messageId Message ID.
	 * @param array $rows Rows indexed by code.
	 * @return void
	 */
	private function replaceRowsByCode(string $tableClass, $messageId, array $rows)
	{
		$storedRows = $tableClass::getList([
			'select' => ['MESSAGE_ID', 'CODE'],
			'filter' => ['=MESSAGE_ID' => $messageId],
		]);

		foreach ($storedRows as $primary)
		{
			if (isset($rows[$primary['CODE']]))
			{
				continue;
			}

			$deleteResult = $tableClass::delete($primary);
			if (!$deleteResult->isSuccess())
			{
				$this->errors->add($deleteResult->getErrors());
			}
		}

		foreach ($rows as $row)
		{
			$addResult = $tableClass::addMerge($row);
			if (!$addResult->isSuccess())
			{
				$this->errors->add($addResult->getErrors());
			}
		}
	}

	/**
	 * @param integer $messageId Message ID.
	 * @param array $fields Fields.
	 * @return array Rows indexed by code.
	 */
	private function buildFieldRows($messageId, array $fields): array
	{
		$rows = [];
		foreach ($fields as $field)
		{
			$code = (string)$field['CODE'];
			if ($code === '' || $this->isGeneratedPersonalizeCode($code))
			{
				continue;
			}

			if (in_array($code, self::PERSONALIZABLE_FIELD_CODES, true))
			{
				$personalizeCode = $code . self::PERSONALIZE_CODE_SUFFIX;
				$personalizeList = $this->extractPersonalizeList($field['VALUE']);
				if ($personalizeList === null)
				{
					unset($rows[$personalizeCode]);
				}
				else
				{
					$rows[$personalizeCode] = [
						'MESSAGE_ID' => $messageId,
						'TYPE' => $field['TYPE'],
						'CODE' => $personalizeCode,
						'VALUE' => $personalizeList,
					];
				}
			}

			$rows[$code] = [
				'MESSAGE_ID' => $messageId,
				'TYPE' => $field['TYPE'],
				'CODE' => $code,
				'VALUE' => $field['VALUE'],
			];
		}

		return $rows;
	}

	/**
	 * @param integer $messageId Message ID.
	 * @param array|null $utmTags Utm tags.
	 * @return array Rows indexed by code.
	 */
	private function buildUtmRows($messageId, $utmTags): array
	{
		$rows = [];
		if (!is_array($utmTags))
		{
			return $rows;
		}

		foreach ($utmTags as $utm)
		{
			if (empty($utm['VALUE']) || empty($utm['CODE']))
			{
				continue;
			}

			$rows[$utm['CODE']] = [
				'MESSAGE_ID' => $messageId,
				'CODE' => $utm['CODE'],
				'VALUE' => $utm['VALUE'],
			];
		}

		return $rows;
	}

	/**
	 * @param string $code Code.
	 * @return bool
	 */
	private function isGeneratedPersonalizeCode(string $code): bool
	{
		if (!str_ends_with($code, self::PERSONALIZE_CODE_SUFFIX))
		{
			return false;
		}

		$sourceCode = substr($code, 0, -strlen(self::PERSONALIZE_CODE_SUFFIX));

		return in_array($sourceCode, self::PERSONALIZABLE_FIELD_CODES, true);
	}

	/**
	 * @param string $value Value.
	 * @return string|null Encoded placeholder list, null when the value has no placeholders.
	 */
	private function extractPersonalizeList($value): ?string
	{
		preg_match_all('/#([0-9a-zA-Z_.|]+?)#/', $value, $placeholders);
		if (count($placeholders[1]) === 0)
		{
			return null;
		}

		return json_encode($placeholders);
	}
}