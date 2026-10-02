<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresSchemaIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CI_PG_SCHEMA_PROBE') !== '1') {
            $this->markTestSkipped('Requires an isolated CI PostgreSQL database.');
        }
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('editorai_ci', config('database.connections.pgsql.database'));
        $this->assertSame('editorai_ci_app', config('database.connections.pgsql.username'));
    }

    public function test_connection_selects_isolated_schema_and_application_tables(): void
    {
        $this->assertSame('editorai', DB::selectOne('select current_schema() as name')->name);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('projects')->count());
        $this->assertGreaterThan(0, DB::table('migrations')->count());
        $this->assertFalse(DB::selectOne('select rolsuper from pg_roles where rolname = current_user')->rolsuper);
    }

    public function test_application_role_cannot_read_another_schema(): void
    {
        $this->expectException(QueryException::class);
        DB::table('foreign_probe.sentinel')->count();
    }

    public function test_application_role_cannot_create_in_public(): void
    {
        $this->expectException(QueryException::class);
        DB::statement('create table public.must_not_exist (id integer)');
    }

    public function test_effective_public_usage_and_own_ddl_are_denied(): void
    {
        $this->assertFalse(DB::selectOne("select has_schema_privilege(current_user, 'public', 'USAGE') as allowed")->allowed);
        $this->expectException(QueryException::class);
        DB::statement('create table must_not_exist (id integer)');
    }

    public function test_application_role_cannot_read_public_sentinel(): void
    {
        $this->expectException(QueryException::class);
        DB::table('public.sentinel')->count();
    }
}
