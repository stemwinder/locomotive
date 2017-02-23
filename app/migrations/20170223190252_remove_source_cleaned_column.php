<?php

use Phinx\Migration\AbstractMigration;

class RemoveSourceCleanedColumn extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('queue');
        $table->removeColumn('source_cleaned')
              ->update();
    }

    public function down()
    {
        $table = $this->table('queue');
        $table->addColumn('source_cleaned', 'boolean', ['default' => false, 'after' => 'is_moved'])
              ->update();
    }
}
