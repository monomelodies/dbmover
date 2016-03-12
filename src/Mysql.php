<?php

namespace Dbmover;

use PDO;

class Mysql extends Dbmover
{
    const CATALOG_COLUMN = 'SCHEMA';
    const DROP_ROUTINE_SUFFIX = '';
    const DROP_CONSTRAINT = 'FOREIGN KEY';

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
    public function isSerial(&$column)
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
    public function isPrimaryKey(&$column)
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
    public function wrapInProcedure($sql)
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
    public function alterColumn($name, array $definition)
    {
        $sql = $this->addColumn($name, $definition);
        $sql = str_replace(
            ' ADD COLUMN ',
            " CHANGE COLUMN {$definition['colname']} ",
            $sql
        );
        if ($definition['is_serial']) {
            $sql .= ' AUTO_INCREMENT';
        }
        return [$sql];
    }

    public function getIndexes()
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

    /**
     * Generate drop statements for all indexes in the database.
     *
     * @return array Array of SQL operations.
     */
    public function dropIndexes()
    {
        $operations = [];
        if ($indexes = $this->getIndexes()) {
            foreach ($indexes as $index) {
                $operations[] = "DROP INDEX {$index['idx']}
                    ON {$index['tbl']}";
            }
        }
        return $operations;
    }

    /**
     * MySQL-specific implementation of getTableDefinition.
     *
     * @param string $name The name of the table.
     * @return array A hash of columns, where the key is also the column name.
     */
    public function getTableDefinition($name)
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT
                COLUMN_NAME colname,
                COLUMN_DEFAULT def,
                IS_NULLABLE nullable,
                DATA_TYPE coltype,
                (EXTRA = 'auto_increment') is_serial
            FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_%s = ? AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION ASC",
            static::CATALOG_COLUMN
        ));
        $stmt->execute([$this->database, $name]);
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (is_null($column['def'])) {
                $column['def'] = 'NULL';
            } else {
                $column['def'] = $this->pdo->quote($column['def']);
            }
            $cols[$column['colname']] = $column;
        }
        return $cols;
    }
}

