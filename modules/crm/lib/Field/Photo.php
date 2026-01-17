<?php

namespace Bitrix\Crm\Field;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\FileUploader;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class Photo extends Field
{
	private FileUploader $fileUploader;

	public function __construct(string $name, array $description)
	{
		parent::__construct($name, $description);

		$this->fileUploader = Container::getInstance()->getFileUploader();
	}

	protected function processLogic(Item $item, Context $context = null): Result
	{
		if ($item->isChanged($this->getName()) && !$this->isItemValueEmpty($item))
		{
			return $this->fileUploader->checkFileById($this, (int)$item->get($this->getName()));
		}

		return new Result();
	}
}
