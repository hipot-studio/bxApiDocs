<?php

namespace Bitrix\Main\Cli\Helper\PathGenerator;

use Bitrix\Main\Cli\Helper\PathGenerator;

trait ChangeablePathGeneratorTrait
{
	private PathGenerator $pathGenerator;

	public function setPathGenerator(PathGenerator $pathGenerator): void
	{
		$this->pathGenerator = $pathGenerator;
	}

	protected function getPathGenerator(): PathGenerator
	{
		$this->pathGenerator ??= new LocalGenerator();

		return $this->pathGenerator;
	}
}
