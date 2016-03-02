<?php

namespace Dbmover;

use PDO;
use PDOException;
use Dariuszp\CliProgressBar;

/**
 * The main Dbmover class. This represents a migration for a single unique DSN
 * from the `dbmover.json` config file.
 */
class Dbmover
{
    private $pdo;
    private $schemas = [];
    private $tables = [];

    /**
     * Constructor.
     *
     * @param string $dsn The DSN string to connect with. Passed verbatim to
     *  PHP's `PDO` constructor.
     * @param array $settings Hash of settings read from `dbmover.json`. The
     *  `"user"` and `"pass"` keys are mostly relevant.
     */
    public function __construct($dsn, array $settings = [])
    {
        $user = isset($settings['user']) ? $settings['user'] : null;
        $pass = isset($settings['pass']) ? $settings['pass'] : null;
        $options = isset($settings['options']) ? $settings['options'] : [];
        $this->pdo = new PDO($dsn, $user, $pass, $options);
    }

    /**
     * Add schema data read from an SQL file.
     *
     * @param string $schema The complete schema.
     */
    public function addSchema($schema)
    {
        $this->schemas[] = $schema;
    }

    /**
     * Process all added schemas.
     *
     */
    public function processSchemas()
    {
        echo "\033[1;32m"
            .str_pad('Gathering operations...', 50, ' ', STR_PAD_RIGHT);
        $sql = implode("\n", $this->schemas);
        $operations = [];
        $alter = $this->hoist( '@^ALTER TABLE .*?;$@ms', $sql);
        $ifs = $this->hoist('@^IF.*?^END;$@ms', $sql);
        $operations = array_merge($operations, $alter, $ifs);
        $tables = $this->hoist('@^CREATE TABLE .*?;$@ms', $sql);

        // Hoist all other recreatable objects.
        $hoists = $this->hoist(
            '@^CREATE (VIEW|PROCEDURE|FUNCTION|TRIGGER).*?^END;$@ms',
            $sql
        );

        foreach ($tables as $table) {
            preg_match('@^CREATE TABLE (\w+) ?\(@', $table, $name);
            $name = $name[1];

            // If the table doesn't exist yet, create it verbatim.
            if (true) {
                $operations[] = $table;
            }
        }

        if (strlen(trim($sql))) {
            $operations[] = $sql;
        }

        foreach ($hoists as $hoist) {
            preg_match('@^CREATE (\w+) (\w+)@', $hoist, $data);
            $operations[] = "DROP {$data[1]} IF EXISTS {$data[2]}";
            $operations[] = $hoist;
        }

        // Rerun ifs and alters
        $operations = array_merge($operations, $alter, $ifs);
        echo "\033[50DPerforming operations...\n";

        echo "\033[0m";

        $bar = new CliProgressBar(count($operations));
        $bar->display();

        $bar->setColorToRed();

        while ($operation = array_shift($operations)) {
            $this->pdo->exec($operation);
            $bar->progress();

            if ($bar->getCurrentstep() >= ($bar->getSteps() / 2)) {
                $bar->setColorToYellow();
            }
        }
        
        $bar->setColorToGreen();
        $bar->display();
        
        $bar->end();
        echo "\033[0m\n\n";
    }

    /**
     * Hoist all matches of $regex from the provided SQL string, and return them
     * as an array for further processing.
     *
     * @param string $regex Regular expression to hoist.
     * @param string $sql Reference to the SQL string to hoist from. Hoisted
     *  statements are removed from this string.
     * @return array An array of hoisted statements (or an empty array if
     *  nothing matched).
     */
    private function hoist($regex, &$sql)
    {
        $hoisted = [];
        if (preg_match_all($regex, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $stmt) {
                $hoisted[] = $stmt[0];
                $sql = str_replace($stmt[0], '', $sql);
            }
        }
        return $hoisted;
    }
}

