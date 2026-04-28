<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Result;

class ReadResult extends Result
{
	public function __construct(
		protected ?int $counter = null,
		protected ?MessageCollection $viewedMessages = null
	)
	{
		parent::__construct();
	}

	public static function error(Error $error): static
	{
		return (new static())->addError($error);
	}

	public function getCounter(): ?int
	{
		return $this->counter;
	}

	public function getViewedMessages(): ?MessageCollection
	{
		return $this->viewedMessages;
	}
}
