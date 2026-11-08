<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes;

use Attribute;
use Bitrix\Disk\BaseObject;

#[Attribute]
class UrlGenerator
{
	/**
	 * @var callable(BaseObject): string
	 */
	public $generator;

	/**
	 * @param callable(BaseObject): string $generator
	 */
	public function __construct(callable $generator)
	{
		$this->generator = $generator;
	}
}
