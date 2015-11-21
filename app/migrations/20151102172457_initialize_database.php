<?php

use Phinx\Migration\AbstractMigration;

class InitializeDatabase extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change()
    {
        $table = $this->table('queue');
        $table->addColumn('hash', 'string')
              ->addColumn('run_id', 'string')
              ->addColumn('name', 'string')
              ->addColumn('host', 'string', ['null' => true])
              ->addColumn('source_dir', 'string', ['null' => true])
              ->addColumn('target_dir', 'string', ['null' => true])
              ->addColumn('size_bytes', 'integer', ['null' => true])
              ->addColumn('file_count', 'integer', ['null' => true])
              ->addColumn('last_modified', 'string', ['null' => true])
              ->addColumn('started_at', 'timestamp', ['null' => true])
              ->addColumn('is_active', 'boolean', ['default' => false])
              ->addColumn('is_finished', 'boolean', ['default' => false])
              ->addColumn('is_moved', 'boolean', ['default' => false])
              ->addColumn('created_at', 'timestamp')
              ->addColumn('updated_at', 'timestamp')
              ->addColumn('deleted_at', 'timestamp', ['null' => true])
              ->create();
        $table
              ->addIndex(['category'])
              ->update();

        $table = $this->table('metrics');
        $table->addColumn('last_run', 'timestamp', ['null' => true])
              ->create();
    }
}
