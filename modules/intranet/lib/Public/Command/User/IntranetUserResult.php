<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Internal\Entity\IntranetUser;
use Bitrix\Main\Result;

class IntranetUserResult extends Result
{
	public function __construct(private readonly ?IntranetUser $intranetUser = null)
	{
		parent::__construct();
	}

	public function getIntranetUser(): ?IntranetUser
	{
		return $this->intranetUser;
	}
}
