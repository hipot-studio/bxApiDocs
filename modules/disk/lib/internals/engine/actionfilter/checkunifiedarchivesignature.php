<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internals\Engine\ActionFilter;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Security\ParameterSigner;
use Bitrix\Disk\Type\ObjectCollection;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class CheckUnifiedArchiveSignature extends ActionFilter\Base
{
	public const ERROR_EMPTY_SIGNATURE = 'empty_signature';
	public const ERROR_INVALID_SIGNATURE = 'invalid_signature';
	public const ERROR_INVALID_ARGUMENTS = 'invalid_arguments';

	public function __construct(
		protected array $requestParameterNames = [
			'signature' => 'signature',
			'skipRootFolder' => 'skipRootFolder',
			'parentFolder' => 'parentFolder',
			'unifiedObjectCollection' => 'unifiedObjectCollection',
		],
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$request = Context::getCurrent()->getRequest();
		$signature = $request->get($this->requestParameterNames['signature']);
		$skipRootFolder = $request->get($this->requestParameterNames['skipRootFolder']) === '1';

		if (!$signature)
		{
			$this->errorCollection[] = new Error(
				message: 'Empty signature',
				code: static::ERROR_EMPTY_SIGNATURE,
			);

			return new EventResult(
				type: EventResult::ERROR,
				handler: $this,
			);
		}

		$arguments = $this->action->getArguments();
		$parentFolder = $arguments[$this->requestParameterNames['parentFolder']] ?? null;
		$unifiedObjectCollection = $arguments[$this->requestParameterNames['unifiedObjectCollection']] ?? null;

		if (
			!$parentFolder instanceof Folder
			|| !$unifiedObjectCollection instanceof ObjectCollection
		)
		{
			$this->errorCollection[] = new Error(
				message: 'Invalid archive arguments',
				code: static::ERROR_INVALID_ARGUMENTS,
			);

			return new EventResult(
				type: EventResult::ERROR,
				handler: $this,
			);
		}

		$isValid = ParameterSigner::validateUnifiedArchiveSignature(
			signature: $signature,
			parentUniqueCode: $parentFolder->getUniqueCode(),
			uniqueCodes: $unifiedObjectCollection->getUniqueCodes(),
			skipRootFolder: $skipRootFolder,
		);

		if (!$isValid)
		{
			$this->errorCollection[] = new Error(
				message: 'Invalid signature',
				code: static::ERROR_INVALID_SIGNATURE,
			);

			return new EventResult(
				type: EventResult::ERROR,
				handler: $this,
			);
		}

		return null;
	}
}
