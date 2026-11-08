<?php
namespace Bitrix\Intranet\UI\LeftMenu\MenuItem;

use Bitrix\Intranet\UI\LeftMenu;

class ItemUserFavorites extends ItemUser
{
	public const PROJECT_ICON_CLASS = 'menu-all-projects';
	public const PARAM_BIND_SLIDER = 'bind_slider';
	public const PARAM_IS_NEW_PROJECT_SECTION = 'is_new_project_section';

	private bool $isProjectSection = false;
	private string $projectSectionLink = '';

	public function getCode(): string
	{
		return 'standard';
	}

	public function markAsProjectSection(string $canonicalLink): void
	{
		$this->isProjectSection = true;
		$this->projectSectionLink = $canonicalLink;
	}

	public function adjustData($data, LeftMenu\User $user): array
	{
		$data = parent::adjustData($data, $user);

		if ($this->isProjectSection)
		{
			$data['LINK'] = $this->projectSectionLink;
			$data['PARAMS']['class'] = self::PROJECT_ICON_CLASS;
			$data['PARAMS'][self::PARAM_BIND_SLIDER] = 'Y';
			$data['PARAMS'][self::PARAM_IS_NEW_PROJECT_SECTION] = 'Y';
		}

		return $data;
	}
}
