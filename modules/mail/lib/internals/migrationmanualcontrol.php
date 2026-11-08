<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\Entity;

final class MigrationManualControlTable extends Entity\DataManager
{
	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_migration_manual_control';
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
			'OPERATION_ID' => [
				'data_type' => 'string',
				'required' => true,
			],
			'ENABLED' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			],
			'CHECKPOINT' => [
				'data_type' => 'string',
				'required' => true,
			],
			'VERSION' => [
				'data_type' => 'integer',
				'default_value' => 0,
			],
			'GRANTED' => [
				'data_type' => 'boolean',
				'values' => ['N', 'Y'],
				'default_value' => 'N',
			],
			'WAIT_SINCE' => ['data_type' => 'datetime'],
			'RETRY_RESUMED_AT' => ['data_type' => 'datetime'],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
			],
			'DATE_MODIFY' => [
				'data_type' => 'datetime',
				'required' => true,
			],
		];
	}
}
