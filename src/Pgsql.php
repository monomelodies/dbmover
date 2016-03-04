<?php

namespace Dbmover;

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
    protected function isAutoIncrement(&$column)
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
    protected function isPrimaryKey(&$column)
    {
        return strpos($column, 'SERIAL');
    }

    /**
     * PostgreSQL-specific `IF` wrapper.
     *
     * @param string $sql The SQL to wrap.
     * @return string The input SQL wrapped and called.
     */
    protected function wrapInProcedure($sql)
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
     * Create the helper procedures (dbm_*).
     */
    protected function createHelperProcedures()
    {
        $this->pdo->exec(sprintf(
            <<<EOT
DROP FUNCTION IF EXISTS dbm_table_exists();
CREATE FUNCTION dbm_table_exists(name TEXT) RETURNS INTEGER AS $$
BEGIN
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE
        TABLE_%s = '%s' AND TABLE_NAME = name INTO @exists;
    RETURN @exists;
END;
$$ LANGUAGE='plpgsql';
EOT
            ,
            self::CATALOG_COLUMN,
            $this->database
        ));
        $this->pdo->exec(sprintf(
            <<<EOT
DROP FUNCTION IF EXISTS dbm_column_exists();
CREATE FUNCTION dbm_column_exists(tablename TEXT, columnname TEXT) RETURNS INTEGER AS $$
BEGIN
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE
        TABLE_%s = '%s' AND TABLE_NAME = tablename AND COLUMN_NAME = columnname
        INTO @exists;
    RETURN @exists;
END;
$$ LANGUAGE='plpgsql';
EOT
            ,
            self::CATALOG_COLUMN,
            $this->database
        ));
        $this->pdo->exec(sprintf(
            <<<EOT
DROP FUNCTION IF EXISTS dbm_column_type();
CREATE FUNCTION dbm_column_exists(tablename TEXT, columnname TEXT) RETURNS INTEGER AS $$
BEGIN
    SELECT LOWER(COLUMN_TYPE) FROM INFORMATION_SCHEMA.COLUMNS WHERE
        TABLE_%s = '%s' AND TABLE_NAME = tablename AND COLUMN_NAME = columnname
        INTO @columntype;
    RETURN @columntype;
END;
$$ LANGUAGE='plpgsql';
EOT
            ,
            self::CATALOG_COLUMN,
            $this->database
        ));
    }
}

