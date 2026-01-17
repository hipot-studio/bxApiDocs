<?php

namespace Bitrix\Tasks\Internals\Task\Template;

use Bitrix\Tasks\Access\Role\RoleDictionary;

class TemplateMemberCollection extends EO_TemplateMember_Collection
{
	public function getAuditorIds(): array
	{
		$auditors = [];

		foreach ($this as $member)
		{
			if ($member->getType() === RoleDictionary::ROLE_AUDITOR)
			{
				$auditors[] = $member->getUserId();
			}
		}

		return $auditors;
	}

	public function getResponsibleIds(): array
	{
		$responsibles = [];

		foreach ($this as $member)
		{
			if ($member->getType() === RoleDictionary::ROLE_RESPONSIBLE)
			{
				$responsibles[] = $member->getUserId();
			}
		}

		return $responsibles;
	}

	public function getAccompliceIds(): array
	{
		$accomplices = [];

		foreach ($this as $member)
		{
			if ($member->getType() === RoleDictionary::ROLE_ACCOMPLICE)
			{
				$accomplices[] = $member->getUserId();
			}
		}

		return $accomplices;
	}
}
