<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Folder;

use Bitrix\Disk\Folder;
use Bitrix\Disk\SystemUser;

final class DeletionRestriction
{
	/** @var DeletionRestrictionRule[] */
	private array $rules;

	public function __construct(?array $rules = null)
	{
		$this->rules = $rules ?? $this->getDefaultRules();
	}

	public function isRestricted(Folder $folder, ?int $deletedBy = null): bool
	{
		if (SystemUser::isSystemUserId($deletedBy))
		{
			return false;
		}

		foreach ($this->rules as $rule)
		{
			if ($rule->isRestricted($folder))
			{
				return true;
			}
		}

		return false;
	}

	public function getRestrictedFolderFilters(?int $deletedBy = null): array
	{
		if (SystemUser::isSystemUserId($deletedBy))
		{
			return [];
		}

		$filters = [];
		foreach ($this->rules as $rule)
		{
			$filter = $rule->getRestrictedFolderFilter();
			if (!empty($filter))
			{
				$filters[] = $filter;
			}
		}

		return $filters;
	}

	private function getDefaultRules(): array
	{
		return [
			new MailAttachmentsFolderDeletionRestrictionRule(),
		];
	}
}
