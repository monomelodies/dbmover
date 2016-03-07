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
        if ($definition['extra'] == 'auto_increment') {
            $sql .= ' AUTO_INCREMENT';
        }
        return [$sql];
    }

    protected function getIndexes()
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT table_name AS tbl,
                index_name idx 
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE table_schema = ?"
        );
        $stmt->execute([$this->database]);
        return $stmt->fetchAll();
    }
}

