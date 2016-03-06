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

    protected function getIndexes()
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
}

