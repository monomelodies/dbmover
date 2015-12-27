
"use strict";

module.exports = function (connection, database, table, callback) {
    connection.query(
        "SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE \n" +
        "WHERE (TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_COlUMN_NAME IS NOT NULL) " +
        "OR (REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?)",
        [database, table, database, table],
        function (err, constraints) {
            if (err) return callback(err);
            var statements = [];
            if (constraints.length) {
                constraints.map(function (constraint) {
                    statements.push("ALTER TABLE " + constraint.TABLE_NAME + " DROP FOREIGN KEY " + constraint.CONSTRAINT_NAME + ";");
                });
            }
            statements.push("DROP TABLE " + table + ";");
            return callback(null, statements);
        }
    );
};

