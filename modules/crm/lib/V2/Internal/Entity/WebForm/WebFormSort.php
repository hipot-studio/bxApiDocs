<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

use Bitrix\Main\ArgumentException;

/**
 * @internal
 */
final class WebFormSort
{
	public const ASC = 'ASC';
	public const DESC = 'DESC';

	/**
	 * @throws ArgumentException
	 */
	public function __construct(
		private readonly string $idDirection = self::ASC,
	)
	{
		if (!in_array($this->idDirection, [self::ASC, self::DESC], true))
		{
			throw new ArgumentException(
				sprintf('Unsupported web form sort direction `%s`', $this->idDirection),
				'order',
			);
		}
	}

	public function getIdDirection(): string
	{
		return $this->idDirection;
	}
}
