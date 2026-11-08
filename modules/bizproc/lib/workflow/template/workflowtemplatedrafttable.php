<?php

namespace Bitrix\Bizproc\Workflow\Template;

use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

/**
 * Class WorkflowTemplateDraftTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowTemplateDraft_Query query()
 * @method static EO_WorkflowTemplateDraft_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowTemplateDraft_Result getById($id)
 * @method static EO_WorkflowTemplateDraft_Result getList(array $parameters = [])
 * @method static EO_WorkflowTemplateDraft_Entity getEntity()
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection createCollection()
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft_Collection wakeUpCollection($rows)
 */
class WorkflowTemplateDraftTable extends DataManager
{
	use DeleteByFilterTrait;
	// TEMPLATE_DATA is passed to updateByFilter() already serialized
	// ({@see Entity\WorkflowTemplateTable::toSerializedForm})
	use UpdateByFilterTrait;

	public const STATUS_DRAFT = 0;
	public const STATUS_AUTOSAVE = 1;
	public const STATUS_RESTORED = 2;
	public const STATUS_RESTORED_WITHOUT_BACKUP = 3;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_template_draft';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\StringField('MODULE_ID'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('ENTITY'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('DOCUMENT_TYPE'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('TEMPLATE_ID')),
			(new ORM\Fields\ArrayField('TEMPLATE_DATA'))
				->configureRequired()
				->configureSerializeCallback([Entity\WorkflowTemplateTable::class, 'toSerializedForm'])
				->configureUnserializeCallback([Entity\WorkflowTemplateTable::class, 'getFromSerializedForm'])
			,
			(new ORM\Fields\IntegerField('STATUS'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new ORM\Fields\IntegerField('REVISION'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new ORM\Fields\IntegerField('USER_ID'))
				->configureNullable(false)
			,
			(new ORM\Fields\DatetimeField('CREATED'))
				->configureNullable(false)
			,
			new ORM\Fields\Relations\Reference(
				'TEMPLATE',
				Entity\WorkflowTemplateTable::class,
				ORM\Query\Join::on('this.TEMPLATE_ID', 'ref.ID')
			),
		];
	}

	public static function getDraftsByTemplateId(int $templateId): array
	{
		return static::getList([
			'filter' => ['=TEMPLATE_ID' => $templateId],
		])->fetchAll();
	}

	/**
	 * The draft the editor itself works with: the freshest one of the template, without its configuration.
	 * Every selection of a draft for the editor goes through this method or its variant carrying the
	 * configuration, a template may have more than one draft.
	 *
	 * @return array<string, mixed>|null null when the template has no draft
	 */
	public static function getLatestDraftByTemplateId(int $templateId): ?array
	{
		return static::selectLatestDraft($templateId, ['ID', 'STATUS', 'REVISION', 'USER_ID', 'CREATED']);
	}

	/**
	 * The freshest draft of the template together with its configuration. Only for callers that really read
	 * the configuration: it is stored as one blob and is the heaviest column of the table.
	 *
	 * @param int|null $userId narrows the search to the drafts of one user, the way a caller working on behalf
	 *        of one person needs it: with the editing lock off a template holds a draft per user, and its
	 *        freshest draft belongs to whoever wrote last, not to the person asking
	 *
	 * @return array<string, mixed>|null null when the template has no such draft
	 */
	public static function getLatestDraftWithDataByTemplateId(int $templateId, ?int $userId = null): ?array
	{
		return static::selectLatestDraft(
			$templateId,
			['ID', 'STATUS', 'REVISION', 'TEMPLATE_DATA', 'CREATED'],
			$userId,
		);
	}

	/**
	 * @return array<string, mixed> empty when the template has no draft
	 */
	public static function getLatestDraftDataByTemplateId(int $templateId): array
	{
		$row = static::getLatestDraftWithDataByTemplateId($templateId);

		return is_array($row['TEMPLATE_DATA'] ?? null) ? $row['TEMPLATE_DATA'] : [];
	}

	/**
	 * The revision the client has to come back with on its next write of the draft. A draft is the draft of
	 * the template only while it is the freshest one: a stale one, and one written past the editor — the one
	 * a restore of a version creates — have nothing to be compared against and answer with a zero.
	 */
	public static function getRevisionIfLatest(int $templateId, int $draftId): int
	{
		if ($templateId <= 0 || $draftId <= 0)
		{
			return 0;
		}

		$latestDraft = static::getLatestDraftByTemplateId($templateId);

		return $latestDraft !== null && (int)$latestDraft['ID'] === $draftId
			? (int)$latestDraft['REVISION']
			: 0
		;
	}

	public static function isRestoredDraft(?array $draftRow): bool
	{
		return $draftRow !== null && (int)$draftRow['STATUS'] === self::STATUS_RESTORED;
	}

	public static function hasRestoredMetadata(?array $draftRow): bool
	{
		return $draftRow !== null
			&& in_array(
				(int)$draftRow['STATUS'],
				[self::STATUS_RESTORED, self::STATUS_RESTORED_WITHOUT_BACKUP],
				true,
			)
		;
	}

	private static function selectLatestDraft(int $templateId, array $select, ?int $userId = null): ?array
	{
		$query = static::query()
			->setSelect($select)
			->where('TEMPLATE_ID', $templateId)
		;

		if ($userId !== null)
		{
			$query->where('USER_ID', $userId);
		}

		$row = $query
			->setOrder(['CREATED' => 'DESC', 'ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	public static function deleteByTemplateId(string $templateId): void
	{
		$iterator =
			static::query()
				->setFilter(['=TEMPLATE_ID' => $templateId])
				->exec()
		;

		while ($draft = $iterator->fetchObject())
		{
			$draft->delete();
		}
	}
}
