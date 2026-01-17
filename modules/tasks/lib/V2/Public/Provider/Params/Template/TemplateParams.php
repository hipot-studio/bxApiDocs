<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class TemplateParams
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $templateId,
		public readonly int $userId,
		public readonly bool $group = true,
		public readonly bool $members = true,
		public readonly bool $checkLists = true,
		public readonly bool $crm = true,
		public readonly bool $tags = true,
		public readonly bool $subTemplates = true,
		public readonly bool $userFields = true,
		public readonly bool $relatedTasks = true,
		public readonly bool $permissions = true,
		public readonly bool $parent = true,
		public readonly bool $checkTemplateAccess = true,
		public readonly bool $checkGroupAccess = true,
		public readonly bool $checkCrmAccess = true,
	)
	{

	}

	public static function mapFromIds(int $templateId, int $userId, array $select = []): static
	{
		$select = [...$select, 'templateId' => $templateId, 'userId' => $userId];

		return static::mapFromArray($select);
	}

	public static function mapFromArray(?array $select): static
	{
		if ($select === null)
		{
			return new static(templateId: 0, userId: 0);
		}

		return new static(
			templateId:   static::mapInteger($select, 'taskId', 0),
			userId:       static::mapInteger($select, 'userId', 0),
			group:        static::mapBool($select, 'group', false),
			members:      static::mapBool($select, 'members', false),
			checkLists:   static::mapBool($select, 'checkLists', false),
			crm:          static::mapBool($select, 'crm', false),
			tags:         static::mapBool($select, 'tags', false),
			subTemplates: static::mapBool($select, 'subTemplates', false),
			userFields:   static::mapBool($select, 'userFields', false),
			relatedTasks: static::mapBool($select, 'relatedTasks', false),
			permissions:  static::mapBool($select, 'permissions', false),
		);
	}
}
