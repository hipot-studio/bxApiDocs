<?php

namespace Bitrix\Crm\Import\Enum\VCard;

enum Column: int
{
	case Version = 0;
	case FullName = 1;
	case Name = 2;
	case Nickname = 3;
	case Photo = 4;
	case Birthday = 5;
	case Anniversary = 6;
	case Gender = 7;

	case Address = 8;

	case Telephone = 9;
	case Email = 10;
	case IM = 11;
	case Language = 12;

	case TimeZone = 13;
	case Geolocation = 14;
	case Url = 23;

	case Title = 15;
	case Role = 16;
	case Logo = 17;
	case Organization = 18;
	case Member = 19;
	case Related = 20;

	case Categories = 21;
	case Note = 22;

	public function getColumnName(): string
	{
		return match($this) {
			self::Version => 'VERSION',
			self::FullName => 'FN',
			self::Name => 'N',
			self::Nickname => 'NICKNAME',
			self::Photo => 'PHOTO',
			self::Birthday => 'BDAY',
			self::Anniversary => 'ANNIVERSARY',
			self::Gender => 'GENDER',

			self::Address => 'ADR',

			self::Telephone => 'TEL',
			self::Email => 'EMAIL',
			self::IM => 'IMPP',
			self::Language => 'LANG',

			self::TimeZone => 'TZ',
			self::Geolocation => 'GEO',
			self::Url => 'URL',

			self::Title => 'TITLE',
			self::Role => 'ROLE',
			self::Logo => 'LOGO',
			self::Organization => 'ORG',
			self::Member => 'MEMBER',
			self::Related => 'RELATED',

			self::Categories => 'CATEGORIES',
			self::Note => 'NOTE',
		};
	}

	public static function tryFromColumnName(string $columnName): ?self
	{
		foreach (self::cases() as $column)
		{
			if ($columnName === $column->getColumnName())
			{
				return $column;
			}
		}

		return null;
	}

	public function isShared(): bool
	{
		return $this->name === 'X-ABLabel';
	}

	public function index(): int
	{
		return $this->value;
	}
}
