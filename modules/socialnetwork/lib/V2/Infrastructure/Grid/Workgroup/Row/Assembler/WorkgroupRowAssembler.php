<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Workgroup\Row\Assembler;

use Bitrix\Main\Grid\Row\Assembler\Field\NumberFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\DateFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\MembersFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\PinFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\PrivacyTypeFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\TagsFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Workgroup\Row\Assembler\Field\WorkgroupNameFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Workgroup\Row\Assembler\Field\WorkgroupRoleFieldAssembler;

class WorkgroupRowAssembler extends RowAssembler
{
	public function __construct(
		array $visibleColumnIds,
		private readonly string $gridId = '',
	)
	{
		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new NumberFieldAssembler(false, ['ID', 'NUMBER_OF_MEMBERS']),
			new WorkgroupNameFieldAssembler(['NAME']),
			new DateFieldAssembler(['DATE_CREATE', 'DATE_ACTIVITY', 'DATE_RELATION', 'DATE_VIEW']),
			new PrivacyTypeFieldAssembler(['PRIVACY_TYPE']),
			new MembersFieldAssembler(['MEMBERS'], $this->gridId, Type::Group->value),
			new PinFieldAssembler(['NAME'], Type::Group->value),
			new WorkgroupRoleFieldAssembler(['ROLE']),
			new TagsFieldAssembler(['TAGS'], $this->gridId, Type::Group->value),
		];
	}
}
