<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Validation\Rule\ExternalAttributes;

class ForceInstallPersonalAppCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly ?string $appClientId,
		public readonly string $title,
		public readonly array $scopes,
		public readonly string $handlerUrl,
		public readonly string $installUrl,
		public readonly bool $onlyApi,
		public readonly bool $mobile,
		public readonly array $menuTitles,
		#[ExternalAttributes]
		public readonly array $attributes = [],
		public readonly ?string $applicationToken = null,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			$data = (new ForceInstallPersonalAppCommandHandler())($this);
			$result->setData([$data]);
		}
		catch (AccessDeniedException | ObjectNotFoundException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}
		catch (ArgumentException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), 'INVALID_ARGUMENT'));
		}
		catch (PersistenceException | SystemException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'appClientId' => $this->appClientId,
			'title' => $this->title,
			'scope' => $this->scopes,
			'handlerUrl' => $this->handlerUrl,
			'installUrl' => $this->installUrl,
			'menuTitles' => $this->menuTitles,
			'onlyApi' => $this->onlyApi,
			'mobile' => $this->mobile,
		];
	}
}
