<?php

namespace Bitrix\Rest\V3\Exception;

use Bitrix\Rest\V3\Attribute\ResolvedBy;
use ReflectionClass;

class RelationMethodNotFoundException extends MethodNotFoundException
{
	public function __construct(ResolvedBy $resolvedBy)
	{
		parent::__construct(strtolower((new ReflectionClass($resolvedBy->controller))->getShortName() . '.list'));
	}
}
