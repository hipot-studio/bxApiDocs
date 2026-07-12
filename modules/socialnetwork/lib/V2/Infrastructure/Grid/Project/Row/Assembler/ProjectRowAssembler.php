<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler;

use Bitrix\Main\Grid\Row\Assembler\Field\NumberFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler\Field\CounterFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler\Field\EfficiencyFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler\Field\NameFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Row\Assembler\Field\RoleFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\ActivityDateFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\DateFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\MembersFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\PinFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\PrivacyTypeFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\TagsFieldAssembler;

class ProjectRowAssembler extends RowAssembler
{
	public function __construct(
		array $visibleColumnIds,
		private readonly string $gridId = '',
		private readonly int $contextUserId = 0,
	)
	{
		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new NumberFieldAssembler(false, ['ID', 'NUMBER_OF_MEMBERS']),
			new NameFieldAssembler(['NAME']),
			new ActivityDateFieldAssembler(['ACTIVITY_DATE']),
			new DateFieldAssembler(['DATE_CREATE', 'DATE_ACTIVITY', 'DATE_RELATION', 'DATE_VIEW', 'PROJECT_DATE_START', 'PROJECT_DATE_FINISH']),
			new EfficiencyFieldAssembler(['EFFICIENCY']),
			new PrivacyTypeFieldAssembler(['PRIVACY_TYPE']),
			new MembersFieldAssembler(['MEMBERS'], $this->gridId, Type::Project->value),
			new PinFieldAssembler(['NAME'], Type::Project->value),
			new RoleFieldAssembler(['ROLE']),
			new TagsFieldAssembler(['TAGS'], $this->gridId, Type::Project->value),
			new CounterFieldAssembler(['ACTIVITY_DATE', 'DATE_ACTIVITY'], $this->contextUserId),
		];
	}
}
