<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList;

use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\NotEmpty;

class FilterItem
{
	public const ALLOWED_PARAMETERS = [
		FieldsDictionary::ID,
		FieldsDictionary::TITLE,
		FieldsDictionary::ACTIVITY_DATE,
		FieldsDictionary::DEADLINE,
		FieldsDictionary::CREATED_DATE,
		FieldsDictionary::CLOSED_DATE,
		FieldsDictionary::START_DATE_PLAN,
		FieldsDictionary::END_DATE_PLAN,
		FieldsDictionary::DATE_START,
		FieldsDictionary::STATUS,
		FieldsDictionary::ALLOW_TIME_TRACKING,
		FieldsDictionary::MARK,
		FieldsDictionary::PRIORITY,
		FieldsDictionary::CREATED_BY,
		FieldsDictionary::RESPONSIBLE_ID,
		FieldsDictionary::GROUP_ID,
		FieldsDictionary::FLOW,
		FieldsDictionary::ACCOMPLICE,
		FieldsDictionary::AUDITOR,
		FieldsDictionary::TAG,
		FieldsDictionary::ADD_IN_REPORT,
		FieldsDictionary::OVERDUED,
		FieldsDictionary::FAVORITE,
		FieldsDictionary::NOT_VIEWED,
		FieldsDictionary::VIEWED,
		FieldsDictionary::IS_MUTED,
		FieldsDictionary::MENTIONED,
		FieldsDictionary::WITH_NEW_COMMENTS,
		FieldsDictionary::MEMBER,
		FieldsDictionary::ACTIVE,
		FieldsDictionary::COMMENT_SEARCH_INDEX,
	];

	public const ALLOWED_OPERATORS = [
		'=',
		'!=',
		'<',
		'>',
		'<=',
		'>=',
		'in',
		'like',
	];

	public const DATE_FIELDS = [
		FieldsDictionary::ACTIVITY_DATE,
		FieldsDictionary::DEADLINE,
		FieldsDictionary::CREATED_DATE,
		FieldsDictionary::CLOSED_DATE,
		FieldsDictionary::START_DATE_PLAN,
		FieldsDictionary::END_DATE_PLAN,
		FieldsDictionary::DATE_START,
	];

	public function __construct(
		#[NotEmpty]
		#[InArray(self::ALLOWED_PARAMETERS)]
		public string $field,
		#[InArray(self::ALLOWED_OPERATORS)]
		public string $operator,
		public mixed $value,
	)
	{
	}
}
