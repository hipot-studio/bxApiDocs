<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

final class CatalogItemColors
{
	private const COLORS = [
		'#41A6FF',
		'#28B774',
		'#FAA72C',
		'#1FBBA6',
		'#B861FF',
		'#FEA8A6',
		'#BBED21',
		'#FF88BA',
		'#26BF4B',
		'#A88145',
		'#2FC6F6',
		'#E5BF00',
	];

	/**
	 * @return string[]
	 */
	public function getAll(): array
	{
		return self::COLORS;
	}

	public function getRandom(): string
	{
		return self::COLORS[array_rand(self::COLORS)];
	}
}
