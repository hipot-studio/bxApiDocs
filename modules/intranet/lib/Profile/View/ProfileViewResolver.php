<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Profile\View;

use Bitrix\Intranet\Entity\User;

final class ProfileViewResolver
{
	/**
	 * @var ProfileViewRule[]
	 */
	private readonly array $rules;

	public function __construct(ProfileViewRule ...$rules)
	{
		$this->rules = $rules;
	}

	public function resolve(User $user): ProfileView
	{
		foreach ($this->rules as $rule)
		{
			if ($rule->matches($user))
			{
				return $rule->createView();
			}
		}

		return ProfileView::default();
	}
}
