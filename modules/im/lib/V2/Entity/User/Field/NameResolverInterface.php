<?php

namespace Bitrix\Im\V2\Entity\User\Field;

interface NameResolverInterface
{
	public function resolveName(int $userId): ?string;
	public function resolveFirstName(int $userId): ?string;
}
