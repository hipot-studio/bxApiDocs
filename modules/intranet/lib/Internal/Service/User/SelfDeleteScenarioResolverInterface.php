<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Public\Type\User\SelfDeleteScenario;

interface SelfDeleteScenarioResolverInterface
{
	public function resolveByUserId(int $userId): SelfDeleteScenario;
	public function resolveByUser(User $user): SelfDeleteScenario;
}
