<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList;

use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\NotEmpty;

class OrderItem
{
	public const ALLOWED_FIELDS = [
		FieldsDictionary::ID,
		FieldsDictionary::TITLE,
		FieldsDictionary::START_DATE_PLAN,
		FieldsDictionary::END_DATE_PLAN,
		FieldsDictionary::ACTIVITY_DATE,
		FieldsDictionary::CREATED_DATE,
		FieldsDictionary::CHANGED_DATE,
		FieldsDictionary::CLOSED_DATE,
		FieldsDictionary::ORIGINATOR_NAME,
		FieldsDictionary::RESPONSIBLE_NAME,
		FieldsDictionary::DEADLINE,
		FieldsDictionary::TIME_ESTIMATE,
		FieldsDictionary::ALLOW_CHANGE_DEADLINE,
		FieldsDictionary::ALLOW_TIME_TRACKING,
		FieldsDictionary::TIME_SPENT_IN_LOGS,
		FieldsDictionary::MARK,
		FieldsDictionary::STATUS,
	];

	public const ALLOWED_DIRECTIONS = [
		'asc',
		'desc',
	];

	public function __construct(
		#[NotEmpty]
		#[InArray(self::ALLOWED_FIELDS)]
		public string $field,
		#[InArray(self::ALLOWED_DIRECTIONS)]
		public string $order,
	)
	{
	}
}
