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
    public function isAutoIncrement(&$column)
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
        // Source: http://stackoverflow.com/questions/7622908/drop-function-without-knowing-the-number-type-of-parameters
        $stmt = $this->pdo->prepare(
            "SELECT format('DROP FUNCTION %s(%s);',
                oid::regproc,
                pg_get_function_identity_arguments(oid)) the_query
            FROM pg_proc
            WHERE proname = ?
            AND pg_function_is_visible(oid)");
        foreach ($this->getRoutines() as $routine) {
            $stmt->execute([$routine['routinename']]);
            while ($query = $stmt->fetchColumn()) {
                $this->pdo->exec($query);
            }
        }
    }
}

