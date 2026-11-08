<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Component\Workgroup;

use Bitrix\Socialnetwork\Helper\Workgroup\Access;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\UI\Toolbar\Facade\Toolbar;

final class FavoritesTitleTemplateResolver
{
	private readonly \Closure $canView;
	private readonly \Closure $deleteFavoriteStar;

	// One title needs one verdict, and it is asked for before the section is known to be available at
	// all. Access::canView() would hydrate GroupRegistry to answer it, reading the whole container with
	// its sites and members; the minimal check reads ID and VISIBLE and gives the same verdict.
	public function __construct(?\Closure $canView = null, ?\Closure $deleteFavoriteStar = null)
	{
		$this->canView = $canView ?? static fn(array $params): bool => Access::canViewWithMinimalData($params);
		$this->deleteFavoriteStar = $deleteFavoriteStar ?? static function (): void
		{
			Toolbar::deleteFavoriteStar();
		};
	}

	public function resolve(
		int $groupId,
		array $groupFields,
		string $titleTemplate,
	): string
	{
		if (($this->canView)(['groupId' => $groupId]))
		{
			return $titleTemplate;
		}

		if (Feature::isSectionPinAvailableForFields($groupFields))
		{
			($this->deleteFavoriteStar)();
		}

		return '';
	}
}
