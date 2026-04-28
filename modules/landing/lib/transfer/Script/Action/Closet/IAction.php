<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action\Closet;

use Bitrix\Landing\Transfer\TransferException;
use Bitrix\Landing\Transfer\Requisite;

interface IAction
{
	public function __construct(Requisite\Context $context);

	/**
	 * Check if making status is end episode
	 * @return bool
	 */
	public function isEndEpisode(): bool;

	/**
	 * Do!
	 * @return void
	 * @throws TransferException
	 */
	public function action(): void;
}
