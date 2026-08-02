<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Control\TemplateMember;

class UpdateMembers
{
	private const MEMBER_FIELDS = ['CREATED_BY', 'RESPONSIBLES', 'ACCOMPLICES', 'AUDITORS'];

	public function __invoke(array $fields): void
	{
		if (!$this->hasMemberFields($fields))
		{
			return;
		}

		$this->createTemplateMember((int)$fields['ID'])->set($fields);
	}

	protected function createTemplateMember(int $templateId): TemplateMember
	{
		return new TemplateMember($templateId);
	}

	private function hasMemberFields(array $fields): bool
	{
		foreach (self::MEMBER_FIELDS as $key)
		{
			if (array_key_exists($key, $fields))
			{
				return true;
			}
		}

		return false;
	}
}
