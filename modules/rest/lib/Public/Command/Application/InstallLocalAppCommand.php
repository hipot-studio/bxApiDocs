<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Entity\Application\AppInstallCompletionMode;
use Bitrix\Rest\Internal\Validation\Rule\ExternalAttributes;

class InstallLocalAppCommand extends Main\Command\AbstractCommand
{
	/**
	 * $onlyApi is the legacy shorthand kept for backward compatibility: true means "no application page
	 * and installation completes at once", so $menuTitles are dropped and the completion mode resolves
	 * to immediate. An explicitly passed $installCompletionMode still wins over that default.
	 */
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
		public readonly ?AppInstallCompletionMode $installCompletionMode = null,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectNotFoundException
	 * @throws AccessDeniedException
	 * @throws PersistenceException
	 * @throws SystemException
	 */
	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		$data = (new InstallLocalAppCommandHandler())($this);
		$result->setData([$data]);

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
			'installCompletionMode' => $this->installCompletionMode?->value,
			'mobile' => $this->mobile,
		];
	}
}
