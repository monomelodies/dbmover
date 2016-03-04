<?php

namespace Dbmover;

class Mysql extends Dbmover
{
    const CATALOG_COLUMN = 'SCHEMA';
    const DROP_ROUTINE_SUFFIX = '';

    /**
     * Process the schemas, wrapped for MySQL.
     */
    public function processSchemas()
    {
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        parent::processSchemas();
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    /**
     * Checks whether a column is an auto_increment column.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    protected function isAutoIncrement(&$column)
    {
        if (strpos($column, 'AUTO_INCREMENT') !== false) {
            $column = str_replace('AUTO_INCREMENT', '', $column);
            return true;
        }
        return false;
    }

    /**
     * Checks whether a column is a primary key.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    protected function isPrimaryKey(&$column)
    {
        if (strpos($column, 'PRIMARY KEY')) {
            $column = str_replace('PRIMARY KEY', '', $column);
            return true;
        }
        return false;
    }

    /**
     * MySQL-specific `IF` wrapper.
     *
     * @param string $sql The SQL to wrap.
     * @return string The input SQL wrapped and called.
     */
    protected function wrapInProcedure($sql)
    {
        $tmp = 'tmp_'.md5(microtime(true));
        return <<<EOT
DROP PROCEDURE IF EXISTS $tmp;
CREATE PROCEDURE $tmp()
BEGIN
    $sql
END;
CALL $tmp();
DROP PROCEDURE $tmp();
EOT;
    }

    /**
     * MySQL-specific ALTER TABLE ... CHANGE COLUMN implementation.
     *
     * @param string $table The table to alter the column on.
     * @param array $definition Hash of desired column definition.
     * @return array An array of SQL, in this case containing a single
     *  ALTER TABLE statement.
     */
    protected function alterColumn($name, array $definition)
    {
        $sql = $this->addColumn($name, $definition);
        $sql = str_replace(
            ' ADD COLUMN ',
            " CHANGE COLUMN {$definition['name']} ",
            $sql
        );
        return [$sql];
    }

    /**
     * Create helper procedures (dbm_*).
     */
    protected function createHelperProcedures()
    {
        $this->pdo->exec(sprintf(
            <<<EOT
DROP FUNCTION IF EXISTS dbm_table_exists;
CREATE FUNCTION dbm_table_exists(name TEXT)
BEGIN
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE
        TABLE_%s = '%s' AND TABLE_NAME = name INTO @exists;
    RETURN @exists;
END;
EOT
            ,
            self::CATALOG_COLUMN,
            $this->database
        ));
        $this->pdo->exec(sprintf(
            <<<EOT
DROP FUNCTION IF EXISTS dbm_column_exists;
CREATE FUNCTION dbm_column_exists(tablename TEXT, columnname TEXT)
BEGIN
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE
        TABLE_%s = '%s' AND TABLE_NAME = tablename AND COLUMN_NAME = columnname
        INTO @exists;
    RETURN @exists;
END;
EOT
            ,
            self::CATALOG_COLUMN,
            $this->database
        ));
        $this->pdo->exec(sprintf(
            <<<EOT
DROP FUNCTION IF EXISTS dbm_column_type;
CREATE FUNCTION dbm_column_exists(tablename TEXT, columnname TEXT)
BEGIN
    SELECT LOWER(COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE
        TABLE_%s = '%s' AND TABLE_NAME = tablename AND COLUMN_NAME = columnname
        INTO @columntype;
    RETURN @columntype;
END;
EOT
            ,
            self::CATALOG_COLUMN,
            $this->database
        ));

    }
}

