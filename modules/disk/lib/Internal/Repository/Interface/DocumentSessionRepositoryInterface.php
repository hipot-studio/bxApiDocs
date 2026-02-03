<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Interface;

use Bitrix\Disk\Internal\Entity\DocumentSessionCollection;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Type\DateTime;

interface DocumentSessionRepositoryInterface extends RepositoryInterface
{
	/**
	 * @param DateTime|null $after fetch only after specific date
	 * @return DocumentSessionCollection
	 */
	public function getOnlyOfficeForDrop(?DateTime $after = null): DocumentSessionCollection;
}