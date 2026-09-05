<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\VendorPack;

enum VendorPackName: string
{
	case BitrixVibe = 'bitrixVibe';
	case Zefir = 'zefir';
	case ArkashaAndCat = 'arkashaAndCat';
	case BitrixReactions = 'bitrixReactions';
	case Airy = 'airy';
	case BittyBob = 'bittyBob';
	case OfficeRoutine = 'officeRoutine';
	case Smileys = 'smileys';
	case Hands = 'hands';
	case WorkDay = 'workDay';
	case Animals = 'animals';
	case Celebration = 'celebration';
	case UnstoppableOptimist = 'unstoppableOptimist';
	case AiHelp = 'aiHelp';
	case LadyBoss = 'ladyBoss';

	private static function getIdMap(): array
	{
		return [
			self::BitrixVibe->value => 1,
			self::Zefir->value => 2,
			self::ArkashaAndCat->value => 3,
			self::BitrixReactions->value => 4,
			self::Airy->value => 5,
			self::BittyBob->value => 6,
			self::OfficeRoutine->value => 7,
			self::Smileys->value => 8,
			self::Hands->value => 9,
			self::WorkDay->value => 10,
			self::Animals->value => 11,
			self::Celebration->value => 12,
			self::UnstoppableOptimist->value => 13,
			self::AiHelp->value => 14,
			self::LadyBoss->value => 15,
		];
	}

	public function getId(): int
	{
		return self::getIdMap()[$this->value];
	}

	public static function getById(int $id): ?self
	{
		$packName = array_search($id, self::getIdMap(), true);

		return $packName !== false ? self::from($packName) : null;
	}
}
