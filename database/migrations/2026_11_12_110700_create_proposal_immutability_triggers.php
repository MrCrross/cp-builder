<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Неизменяемость выпущенного контента (план §7.1 п.6): PostgreSQL trigger
 * блокирует изменение content-полей и детей версий issued/superseded,
 * допуская только контролируемый переход issued → superseded (без изменения
 * прочих колонок). Application-уровень дублирует проверку для sqlite-тестов.
 * Имя функции cp_* — не в схеме приложения, удаляется вместе с миграцией.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            create or replace function cp_versions_immutable() returns trigger as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'Версии удаляются только каскадно вместе с невыпущенным черновиком';
                end if;

                if old.state = 'draft' then
                    return new;
                end if;

                if old.state = 'issued' and new.state = 'superseded'
                    and new.id is not distinct from old.id
                    and new.proposal_id is not distinct from old.proposal_id
                    and new.version is not distinct from old.version
                    and new.title is not distinct from old.title
                    and new.subtitle is not distinct from old.subtitle
                    and new.client_name is not distinct from old.client_name
                    and new.client_contact is not distinct from old.client_contact
                    and new.manager_name is not distinct from old.manager_name
                    and new.manager_email is not distinct from old.manager_email
                    and new.manager_phone is not distinct from old.manager_phone
                    and new.note is not distinct from old.note
                    and new.currency is not distinct from old.currency
                    and new.total_min is not distinct from old.total_min
                    and new.total_max is not distinct from old.total_max
                    and new.branding_snapshot is not distinct from old.branding_snapshot
                    and new.created_by is not distinct from old.created_by
                    and new.issued_at is not distinct from old.issued_at
                then
                    return new;
                end if;

                raise exception 'Выпущенные версии неизменяемы';
            end;
            $$ language plpgsql;

            create trigger proposals_versions_immutable
                before update or delete on proposal_versions
                for each row execute function cp_versions_immutable();

            create or replace function cp_version_children_immutable() returns trigger as $$
            declare
                parent_state text;
            begin
                select state into parent_state from proposal_versions where id = coalesce(old.version_id, new.version_id);

                if parent_state is distinct from 'draft' then
                    raise exception 'Содержимое выпущенной версии неизменяемо';
                end if;

                return coalesce(new, old);
            end;
            $$ language plpgsql;

            create trigger proposals_items_immutable
                before update or delete on proposal_items
                for each row execute function cp_version_children_immutable();

            create trigger proposals_sections_immutable
                before update or delete on proposal_sections
                for each row execute function cp_version_children_immutable();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            drop trigger if exists proposals_versions_immutable on proposal_versions;
            drop trigger if exists proposals_items_immutable on proposal_items;
            drop trigger if exists proposals_sections_immutable on proposal_sections;
            drop function if exists cp_versions_immutable();
            drop function if exists cp_version_children_immutable();
        SQL);
    }
};
