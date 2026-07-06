<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

use Closure;

final class SystemFolderPrototype
{
	/**
	 * @param Closure(): SystemFolder $factory
	 */
	public function __construct(
		public readonly string $code,
		private readonly Closure $factory,
	) {}

	public function instantiate(): SystemFolder
	{
		return ($this->factory)();
	}
}
