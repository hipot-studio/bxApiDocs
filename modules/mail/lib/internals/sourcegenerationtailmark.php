<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Entity;

/**
 * Class SourceGenerationTailMarkTable
 *
 * The record behind the fallback recognition of a letter the tail append stage put onto a
 * prepared generation itself: a source that answers no coordinates to an append leaves
 * nothing a verdict of the matching journal could be addressed by, so the letter carries a
 * one time mark of ours instead and this row is what that mark leads to.
 *
 * The mark itself is never here - only MARK_HASH, the SHA-256 of it in hex, the way the
 * fingerprints of the matching keep their values. The row is written BEFORE the letter
 * reaches the server, and it is the record and not the header that hands out the identity:
 * a mark with no row of its own recognizes nothing.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SourceGenerationTailMark_Query query()
 * @method static EO_SourceGenerationTailMark_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SourceGenerationTailMark_Result getById($id)
 * @method static EO_SourceGenerationTailMark_Result getList(array $parameters = [])
 * @method static EO_SourceGenerationTailMark_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\EO_SourceGenerationTailMark createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_SourceGenerationTailMark_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\EO_SourceGenerationTailMark wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_SourceGenerationTailMark_Collection wakeUpCollection($rows)
 */
class SourceGenerationTailMarkTable extends Entity\DataManager
{
	/** The letter is on its way to the new source, or already there and not read back yet */
	public const STATE_PENDING = 'PENDING';

	/** The reverse read has recognized the letter by this mark: the row is terminal */
	public const STATE_USED = 'USED';

	/** The coordinates of IMAP: unsigned 32-bit numbers, past the signed range of a 4-byte integer */
	private const UNSIGNED_INT_FIELDS = ['DIR_UIDV', 'MSG_UID'];

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_source_generation_tail_mark';
	}

	public static function add(array $data)
	{
		return parent::add(self::keepUnsignedIntValues($data));
	}

	public static function update($primary, array $data)
	{
		return parent::update($primary, self::keepUnsignedIntValues($data));
	}

	public static function getMap()
	{
		return [
			'ID' => [
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			],
			'MAILBOX_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'GENERATION_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'MESSAGE_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'MARK_HASH' => [
				'data_type' => 'string',
				'required' => true,
			],
			'STATE' => [
				'data_type' => 'enum',
				'required' => true,
				'values' => [
					self::STATE_PENDING,
					self::STATE_USED,
				],
			],
			'DIR_MD5' => [
				'data_type' => 'string',
			],
			'DIR_UIDV' => [
				'data_type' => 'integer',
				'size' => 8,
				'default_value' => 0,
			],
			'MSG_UID' => [
				'data_type' => 'integer',
				'size' => 8,
				'default_value' => 0,
			],
			'ATTEMPTS' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
			],
			'DATE_UPDATE' => [
				'data_type' => 'datetime',
			],
			'GENERATION' => [
				'data_type' => 'Bitrix\Mail\Internals\MailboxSourceGeneration',
				'reference' => ['=this.GENERATION_ID' => 'ref.ID'],
			],
		];
	}

	/**
	 * The SQL helpers write an integer as a 4-byte one unless told otherwise and clamp a value above
	 * 2147483647 to it, whatever the bigint column could hold. An expression is passed as it is.
	 */
	private static function keepUnsignedIntValues(array $fields): array
	{
		foreach (self::UNSIGNED_INT_FIELDS as $fieldName)
		{
			if (isset($fields[$fieldName]) && is_numeric($fields[$fieldName]))
			{
				$fields[$fieldName] = new SqlExpression('?i', $fields[$fieldName]);
			}
		}

		return $fields;
	}
}
