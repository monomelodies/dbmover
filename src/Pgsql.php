<?php

namespace Dbmover;

use PDO;

class Pgsql extends Dbmover
{
    const REGEX_PROCEDURES = "@^CREATE (FUNCTION|PROCEDURE).*?AS.*?LANGUAGE '.*?';$@ms";
    const REGEX_TRIGGERS = '@^CREATE TRIGGER.*?EXECUTE PROCEDURE.*?;$@ms';

    /**
     * Checks whether a column is an auto_increment column.
     *
     * @param string $column The referenced column definition.
     * @return bool
     */
    public function isSerial(&$column)
    {
        if (preg_match('@SERIAL@', $column)) {
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
        return strpos($column, 'SERIAL');
    }

    /**
     * PostgreSQL-specific `IF` wrapper.
     *
     * @param string $sql The SQL to wrap.
     * @return string The input SQL wrapped and called.
     */
    public function wrapInProcedure($sql)
    {
        $tmp = 'tmp_'.md5(microtime(true));
        return <<<EOT
DROP FUNCTION IF EXISTS $tmp();
CREATE FUNCTION $tmp() RETURNS void AS $$
BEGIN
    $sql
END;
$$ LANGUAGE 'plpgsql';
SELECT $tmp();
DROP FUNCTION $tmp();
EOT;
    }

    /**
     * PostgreSQL-specific implementation of getTableDefinition.
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
                COLUMN_DEFAULT LIKE 'nextval(%%' is_serial
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

    public function getIndexes()
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                idx.indrelid :: REGCLASS tbl,
                i.relname idx
            FROM pg_index AS idx
                JOIN pg_class AS i ON i.oid = idx.indexrelid
                JOIN pg_am AS am ON i.relam = am.oid
                JOIN pg_namespace AS NS ON i.relnamespace = NS.OID
                JOIN pg_user AS U ON i.relowner = U.usesysid
            WHERE NOT nspname LIKE 'pg%' AND U.usename = ?");
        $stmt->execute([$this->database]);
        return $stmt->fetchAll();
    }

    public function dropRoutines()
    {
        $operations = [];
        // Source: http://stackoverflow.com/questions/7622908/drop-function-without-knowing-the-number-type-of-parameters
        $stmt = $this->pdo->prepare(
            "SELECT format('DROP FUNCTION %s(%s);',
                oid::regproc,
                pg_get_function_identity_arguments(oid)) the_query
            FROM pg_proc
            WHERE proname = ?
            AND pg_function_is_visible(oid)");
        foreach ($this->getRoutines() as $routine) {
            if (!$this->shouldIgnore($routine['routinename'])) {
                $stmt->execute([$routine['routinename']]);
                while ($query = $stmt->fetchColumn()) {
                    $operations[] = $query;
                }
            }
        }
        return $operations;
    }

    public function dropTriggers()
    {
        $tmp = md5(microtime(true));
        return [<<<EOT
CREATE OR REPLACE FUNCTION strip_$tmp() RETURNS text AS $$ DECLARE
triggNameRecord RECORD;
triggTableRecord RECORD;
BEGIN
    FOR triggNameRecord IN select distinct(trigger_name) from information_schema.triggers where trigger_schema = 'public' LOOP
        FOR triggTableRecord IN SELECT distinct(event_object_table) from information_schema.triggers where trigger_name = triggNameRecord.trigger_name LOOP
            EXECUTE 'DROP TRIGGER ' || triggNameRecord.trigger_name || ' ON ' || triggTableRecord.event_object_table || ';';
        END LOOP;
    END LOOP;
    RETURN 'done';
END;
$$ LANGUAGE plpgsql;
select strip_$tmp();
DROP FUNCTION strip_$tmp();
EOT
        ];
    }
}

