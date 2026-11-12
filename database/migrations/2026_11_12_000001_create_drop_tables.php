<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('files', static function (Table $table): void {
            $table->string('id', 7);
            $table->primary(['id']);
            $table->string('object_key')->unique();
            $table->string('name');
            $table->bigInteger('size');
            $table->string('upload_token_hash', 64);
            $table->string('state', 16)->default('pending');
            $table->bigInteger('upload_until');
            $table->bigInteger('cleanup_after')->index();
            $table->bigInteger('created_at');
            $table->bigInteger('expires_at')->nullable()->index();
        });
        $schema->create('admin_sessions', static function (Table $table): void {
            $table->string('token_hash', 64);
            $table->primary(['token_hash']);
            $table->string('credential_hash', 64);
            $table->bigInteger('expires_at')->index();
        });
        $schema->create('rate_limits', static function (Table $table): void {
            $table->string('key', 64);
            $table->primary(['key']);
            $table->integer('hits');
            $table->bigInteger('expires_at')->index();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('rate_limits');
        $schema->drop('admin_sessions');
        $schema->drop('files');
    }
};
